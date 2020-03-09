<?php declare(strict_types=1);
namespace NAVIT\GitHub;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7;
use InvalidArgumentException;
use RuntimeException;

class ApiClient {
    /**
     * @var string
     */
    private $organization;

    /**
     * @var HttpClient
     */
    private $httpClient;

    /**
     * Class constructor
     *
     * @param string $organization The organization to use
     * @param string $personalAccessToken The personal access token to use
     * @param HttpClient $httpClient Pre-configured HTTP client to use
     */
    public function __construct(string $organization, string $personalAccessToken, HttpClient $httpClient = null) {
        $this->organization = $organization;
        $this->httpClient = $httpClient ?: new HttpClient([
            'base_uri' => 'https://api.github.com/',
            'auth' => ['x-access-token', $personalAccessToken],
            'headers' => [
                'Accept' => 'application/json'
            ],
        ]);
    }

    /**
     * Get a team by slug
     *
     * @param string $slug Slug of the team (name)
     * @return Models\Team|null
     */
    public function getTeam(string $slug) : ?Models\Team {
        try {
            $response = $this->httpClient->get(sprintf('orgs/%s/teams/%s', $this->organization, $slug));
        } catch (ClientException $e) {
            return null;
        }

        return Models\Team::fromApiResponse($response);
    }

    /**
     * Create a new team
     *
     * @param string $name The name of the team
     * @param string $description The description of the team
     * @throws InvalidArgumentException
     * @return Models\Team
     */
    public function createTeam(string $name, string $description) : Models\Team {
        try {
            $response = $this->httpClient->post(sprintf('orgs/%s/teams', $this->organization), [
                'json' => [
                    'name'        => $name,
                    'description' => $description,
                    'privacy'     => 'closed'
                ],
            ]);
            } catch (ClientException $e) {
                throw new RuntimeException('Unable to create team', $e->getCode(), $e);
            }

        return Models\Team::fromApiResponse($response);
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
     * @return string|null
     */
    public function getSamlId(string $username) : ?string {
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
                throw new RuntimeException('Unable to get SAML ID', $e->getCode(), $e);
            }

            $data     = json_decode($response->getBody()->getContents(), true);
            $pageInfo = $data['data']['organization']['samlIdentityProvider']['externalIdentities']['pageInfo'];
            $nodes    = $data['data']['organization']['samlIdentityProvider']['externalIdentities']['nodes'];
            $offset   = $pageInfo['endCursor'];

            foreach ($nodes as $entity) {
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
     * @param int $teamId The numeric ID of the GitHub team
     * @param string $groupId ID of the AAD group
     * @param string $displayName The display name of the AAD group
     * @param string $description The description of the AAD group
     * @throws RuntimeException
     * @return bool
     */
    public function syncTeamAndGroup(int $teamId, string $groupId, string $displayName, string $description) : bool {
        try {
            $this->httpClient->patch(sprintf('teams/%d/team-sync/group-mappings', $teamId), [
                'json' => [
                    'groups' => [[
                        'group_id'          => $groupId,
                        'group_name'        => $displayName,
                        'group_description' => $description,
                    ]]
                ]
            ]);
        } catch (ClientException $e) {
            throw new RuntimeException('Unable to sync team and group', $e->getCode(), $e);
        }

        return true;
    }

    /**
     * Set team description
     *
     * @param string $slug The team slug (name)
     * @param string $description The description
     * @throws InvalidArgumentException|RuntimeException
     * @return Models\Team
     */
    public function setTeamDescription(string $slug, string $description) : Models\Team {
        try {
            $response = $this->httpClient->get(sprintf('orgs/%s/teams/%s', $this->organization, $slug));
        } catch (ClientException $e) {
            throw new InvalidArgumentException('Team does not exist', $e->getCode(), $e);
        }

        $teamId = json_decode($response->getBody()->getContents(), true)['id'];

        try {
            $response = $this->httpClient->patch(sprintf('teams/%d', $teamId), [
                'json' => [
                    'description' => $description,
                ],
            ]);
        } catch (ClientException $e) {
            throw new RuntimeException('Unable to update description', $e->getCode(), $e);
        }

        return Models\Team::fromApiResponse($response);
    }

    /**
     * Get the next URL given a Link-header
     *
     * @param array $linkHeader
     * @return ?string
     */
    private function getNextUrl(array $linkHeader) : ?string {
        foreach (Psr7\parse_header($linkHeader) as $link) {
            if (!empty($link['rel']) && 'next' === $link['rel']) {
                return trim($link[0], '<>');
            }
        }

        return null;
    }

    /**
     * Get all repos
     *
     * Fetch all repos connected to the organization the client is set up for.
     *
     * @return array
     */
    public function getRepos() : array {
        $repos = [];
        $url = sprintf('orgs/%s/repos?per_page=100', $this->organization);

        while ($url) {
            $response = $this->httpClient->get($url);
            $repos    = array_merge($repos, json_decode($response->getBody()->getContents(), true));
            $url      = $this->getNextUrl($response->getHeader('Link'));
        }

        return $repos;
    }
}