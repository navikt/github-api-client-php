<?php declare(strict_types=1);
namespace NAVIT\GitHub;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Header;
use InvalidArgumentException;
use RuntimeException;

class ApiClient
{
    private string $organization;
    private HttpClient $httpClient;

    /**
     * Class constructor
     *
     * @param string $organization The organization to use
     * @param string $personalAccessToken The personal access token to use
     * @param HttpClient $httpClient Pre-configured HTTP client to use
     */
    public function __construct(string $organization, string $personalAccessToken, HttpClient $httpClient = null)
    {
        $this->organization = $organization;
        $this->httpClient = $httpClient ?: new HttpClient([
            'base_uri' => 'https://api.github.com/',
            'auth' => ['x-access-token', $personalAccessToken],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Get a team by slug
     *
     * @param string $slug Slug of the team (name)
     * @return ?array<string,mixed>
     */
    public function getTeam(string $slug): ?array
    {
        try {
            $response = $this->httpClient->get(sprintf('orgs/%s/teams/%s', $this->organization, $slug));
        } catch (ClientException $e) {
            return null;
        }

        /** @var array<string,mixed> */
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Create a new team
     *
     * @param string $name The name of the team
     * @param string $description The description of the team
     * @throws InvalidArgumentException
     * @return array<string,mixed>
     */
    public function createTeam(string $name, string $description): array
    {
        try {
            $response = $this->httpClient->post(sprintf('orgs/%s/teams', $this->organization), [
                'json' => [
                    'name'        => $name,
                    'description' => $description,
                    'privacy'     => 'closed',
                ],
            ]);
        } catch (ClientException $e) {
            throw new RuntimeException('Unable to create team', (int) $e->getCode(), $e);
        }

        /** @var array<string,mixed> */
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get the SAML ID (Azure AD email address) from a GitHub login
     *
     * This process is slow because of the way the API is designed. There is no way to filter on
     * a specific user, so we need to loop over all entities governed by SCIM until we find the
     * matching GitHub login. Until GitHub fixes their API this is the only solution.
     *
     * @param string $username The GitHub login
     * @throws RuntimeException
     * @return ?string
     */
    public function getSamlId(string $username): ?string
    {
        $offset = null;
        $query = <<<GQL
        query {
            organization(login: "%s") {
                samlIdentityProvider {
                    externalIdentities(first: 100 %s) {
                        pageInfo {
                            endCursor
                            startCursor
                            hasNextPage
                        }
                        nodes {
                            samlIdentity {
                                nameId
                            }
                            user {
                                login
                            }
                        }
                    }
                }
            }
        }
GQL;

        do {
            try {
                $response = $this->httpClient->post('graphql', [
                    'json' => ['query' => sprintf($query, $this->organization, $offset ? sprintf('after: "%s"', $offset) : '')],
                ]);
            } catch (ClientException $e) {
                throw new RuntimeException('Unable to get SAML ID', (int) $e->getCode(), $e);
            }

            /**
             * @var array{
             *   data:array{
             *     organization:array{
             *       samlIdentityProvider:array{
             *         externalIdentities:array{
             *           pageInfo:array{
             *             endCursor:string,
             *             hasNextPage:bool
             *           },
             *           nodes:array<
             *             array{
             *               samlIdentity:array{
             *                 nameId:string
             *               },
             *               user?:array{
             *                 login:string
             *               }
             *             }
             *           >
             *         }
             *       }
             *     }
             *   }
             * }
             * */
            $data     = json_decode($response->getBody()->getContents(), true);
            $pageInfo = $data['data']['organization']['samlIdentityProvider']['externalIdentities']['pageInfo'];
            $nodes    = $data['data']['organization']['samlIdentityProvider']['externalIdentities']['nodes'];
            $offset   = $pageInfo['endCursor'];

            foreach ($nodes as $entity) {
                if (empty($entity['user'])) {
                    continue;
                }

                if ($entity['user']['login'] === $username) {
                    return $entity['samlIdentity']['nameId'];
                }
            }
        } while ($pageInfo['hasNextPage']);

        return null;
    }

    /**
     * Connect a GitHub team with an Azure AD group
     *
     * @param string $slug The team slug
     * @param string $groupId ID of the AAD group
     * @param string $displayName The display name of the AAD group
     * @param string $description The description of the AAD group
     * @throws RuntimeException
     * @return bool
     */
    public function syncTeamAndGroup(string $slug, string $groupId, string $displayName, string $description): bool
    {
        try {
            $this->httpClient->patch(sprintf('orgs/%s/teams/%s/team-sync/group-mappings', $this->organization, $slug), [
                'json' => [
                    'groups' => [[
                        'group_id'          => $groupId,
                        'group_name'        => $displayName,
                        'group_description' => $description,
                    ]],
                ],
            ]);
        } catch (ClientException $e) {
            throw new RuntimeException('Unable to sync team and group', (int) $e->getCode(), $e);
        }

        return true;
    }

    /**
     * Set team description
     *
     * @param string $slug The team slug (name)
     * @param string $description The description
     * @throws InvalidArgumentException|RuntimeException
     * @return array<string,mixed>
     */
    public function setTeamDescription(string $slug, string $description): array
    {
        try {
            $response = $this->httpClient->get(sprintf('orgs/%s/teams/%s', $this->organization, $slug));
        } catch (ClientException $e) {
            throw new InvalidArgumentException('Team does not exist', (int) $e->getCode(), $e);
        }

        /** @var array{id:int} */
        $team = json_decode($response->getBody()->getContents(), true);

        try {
            $response = $this->httpClient->patch(sprintf('teams/%d', (int) $team['id']), [
                'json' => [
                    'description' => $description,
                ],
            ]);
        } catch (ClientException $e) {
            throw new RuntimeException('Unable to update description', (int) $e->getCode(), $e);
        }

        /** @var array<string,mixed> */
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get all repos
     *
     * Fetch all repos connected to the organization the client is set up for.
     *
     * @return array<array<string,mixed>>
     */
    public function getRepos(): array
    {
        return $this->getPaginatedResult(sprintf('orgs/%s/repos', $this->organization));
    }

    /**
     * Get all organization members
     *
     * @return array<array<string,mixed>>
     */
    public function getMembers(): array
    {
        return $this->getPaginatedResult(sprintf('orgs/%s/members', $this->organization));
    }

    /**
     * Get the next URL given a Link-header
     *
     * @param string[] $linkHeader
     * @return ?string
     */
    private function getNextUrl(array $linkHeader): ?string
    {
        /** @var array<array{0:string,rel?:string}> */
        $links = Header::parse($linkHeader);

        foreach ($links as $link) {
            if (array_key_exists('rel', $link) && 'next' === $link['rel']) {
                return trim((string) $link[0], '<>');
            }
        }

        return null;
    }

    /**
     * Get paginated results
     *
     * @param string $url
     * @return array<array<string,mixed>>
     */
    private function getPaginatedResult(string $url): array
    {
        $requestOptions = [
            'query' => [
                'per_page' => 100,
            ],
        ];
        $entities = [];

        while ($url) {
            $response = $this->httpClient->get($url, $requestOptions);

            /** @var array<array<string,mixed>> */
            $body           = json_decode($response->getBody()->getContents(), true);
            $entities       = array_merge($entities, $body);
            $url            = $this->getNextUrl($response->getHeader('Link'));
            $requestOptions = []; // We only need this for the first request
        }

        return $entities;
    }

    /**
     * Create a workflow dispatch event
     *
     * @param string $repo The repository that owns the workflow
     * @param string $workflow The name of the workflow file or the workflow ID
     * @param string $ref The git reference for the workflow
     * @param array<string,mixed> $inputs Optional inputs for the workflow
     * @throws RuntimeException
     * @return void
     */
    public function dispatchWorkflow(string $repo, string $workflow, string $ref, array $inputs = []): void
    {
        try {
            $this->httpClient->post(sprintf(
                'repos/%s/%s/actions/workflows/%s/dispatches',
                $this->organization,
                $repo,
                $workflow,
            ), [
                'json' => array_filter([
                    'ref' => $ref,
                    'inputs' => $inputs,
                ]),
            ]);
        } catch (ClientException $e) {
            throw new RuntimeException('Unable to create workflow dispatch event', (int) $e->getCode(), $e);
        }
    }
}
