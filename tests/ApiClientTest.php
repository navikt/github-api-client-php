<?php declare(strict_types=1);
namespace NAVIT\GitHub;

use NAVIT\GitHub\Models\Team;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Middleware;
use InvalidArgumentException;
use RuntimeException;

/**
 * @coversDefaultClass NAVIT\GitHub\ApiClient
 */
class ApiClientTest extends TestCase {
    private function getMockClient(array $responses, array &$history = []) : HttpClient {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));

        return new HttpClient(['handler' => $handler]);
    }

    /**
     * @covers ::getTeam
     */
    public function testCanGetTeam() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [new Response(200, [], '{"id": 123, "name": "team"}')],
            $clientHistory
        );

        $githubTeam = (new ApiClient('navikt', 'access-token', $httpClient))->getTeam('team');

        $this->assertCount(1, $clientHistory, 'Expected one request');
        $this->assertInstanceOf(Team::class, $githubTeam);
        $this->assertSame('orgs/navikt/teams/team', (string) $clientHistory[0]['request']->getUri());
    }

    /**
     * @covers ::getTeam
     */
    public function testReturnsNullWhenTeamIsNotFound() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [new ClientException(
                'team not found',
                new Request('GET', 'orgs/navikt/teams/team'),
                new Response(404)
            )],
            $clientHistory
        );

        $this->assertNull((new ApiClient('navikt', 'access-token', $httpClient))->getTeam('team'));
    }

    /**
     * @covers ::getTeam
     */
    public function testReturnsNullOnError() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [new ClientException(
                'Forbidden',
                new Request('GET', 'orgs/navikt/teams/team'),
                new Response(403)
            )],
            $clientHistory
        );

        $this->assertNull((new ApiClient('navikt', 'access-token', $httpClient))->getTeam('team'));
    }

    /**
     * @covers ::createTeam
     */
    public function testCanCreateTeam() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [new Response(201, [], '{"id": 123, "name": "team-name"}')],
            $clientHistory
        );

        $githubTeam = (new ApiClient('navikt', 'access-token', $httpClient))->createTeam('team-name', 'team description');

        $this->assertInstanceOf(Team::class, $githubTeam);
        $this->assertCount(1, $clientHistory, 'Expected one request');

        $request = $clientHistory[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('orgs/navikt/teams', (string) $request->getUri());

        $body = json_decode($request->getBody()->getContents(), true);

        $this->assertSame('team-name', $body['name'], 'Team name not correct');
        $this->assertStringStartsWith('team description', $body['description'], 'Team description not correct');
    }

    /**
     * @covers ::syncTeamAndGroup
     */
    public function testTeamSync() : void {
        $teamId = 123;
        $groupId = '123abc';
        $displayName = 'group name';
        $description = 'group description';

        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [new Response(201)],
            $clientHistory
        );

        $this->assertTrue((new ApiClient('navikt', 'access-token', $httpClient))->syncTeamAndGroup($teamId, $groupId, $displayName, $description));

        $this->assertCount(1, $clientHistory, 'Expected one request');

        $request = $clientHistory[0]['request'];
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('teams/123/team-sync/group-mappings', (string) $request->getUri());
        $body = json_decode($request->getBody()->getContents(), true);

        $this->assertSame([
            'groups' => [
                [
                    'group_id'          => '123abc',
                    'group_name'        => 'group name',
                    'group_description' => 'group description',
                ]
            ]
        ], $body);
    }

    /**
     * @covers ::getSamlId
     */
    public function testCanGetSamlId() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [
                new Response(200, [], json_encode([
                    'data' => [
                        'organization' => [
                            'samlIdentityProvider' => [
                                'externalIdentities' => [
                                    'pageInfo' => [
                                        'endCursor'   => 'some-cursor',
                                        'hasNextPage' => true,
                                    ],
                                    'nodes' => [
                                        [
                                            'user' => [
                                                'login' => 'user1',
                                            ],
                                            'samlIdentity' => [
                                                'nameId' => 'user1@nav.no'
                                            ],
                                        ],
                                        [
                                            'user' => [
                                                'login' => 'user2',
                                            ],
                                            'samlIdentity' => [
                                                'nameId' => 'user2@nav.no'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ])),

                new Response(200, [], json_encode([
                    'data' => [
                        'organization' => [
                            'samlIdentityProvider' => [
                                'externalIdentities' => [
                                    'pageInfo' => [
                                        'endCursor'   => null,
                                        'hasNextPage' => false,
                                    ],
                                    'nodes' => [
                                        [
                                            'user' => [
                                                'login' => 'user3',
                                            ],
                                            'samlIdentity' => [
                                                'nameId' => 'user3@nav.no'
                                            ],
                                        ],
                                        [
                                            'user' => [
                                                'login' => 'user4',
                                            ],
                                            'samlIdentity' => [
                                                'nameId' => 'user4@nav.no'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ])),
            ],
            $clientHistory
        );

        $this->assertSame('user3@nav.no', (new ApiClient('navikt', 'access-token', $httpClient))->getSamlId('user3'));
    }

    /**
     * @covers ::getSamlId
     */
    public function testReturnsNullWhenSamlIdDoesNotExist() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [
                new Response(200, [], json_encode([
                    'data' => [
                        'organization' => [
                            'samlIdentityProvider' => [
                                'externalIdentities' => [
                                    'pageInfo' => [
                                        'endCursor'   => 'some-cursor',
                                        'hasNextPage' => true,
                                    ],
                                    'nodes' => [
                                        [
                                            'user' => [
                                                'login' => 'user1',
                                            ],
                                            'samlIdentity' => [
                                                'nameId' => 'user1@nav.no'
                                            ],
                                        ],
                                        [
                                            'user' => [
                                                'login' => 'user2',
                                            ],
                                            'samlIdentity' => [
                                                'nameId' => 'user2@nav.no'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ])),

                new Response(200, [], json_encode([
                    'data' => [
                        'organization' => [
                            'samlIdentityProvider' => [
                                'externalIdentities' => [
                                    'pageInfo' => [
                                        'endCursor'   => null,
                                        'hasNextPage' => false,
                                    ],
                                    'nodes' => [
                                        [
                                            'user' => [
                                                'login' => 'user3',
                                            ],
                                            'samlIdentity' => [
                                                'nameId' => 'user3@nav.no'
                                            ],
                                        ],
                                        [
                                            'user' => [
                                                'login' => 'user4',
                                            ],
                                            'samlIdentity' => [
                                                'nameId' => 'user4@nav.no'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ])),
            ],
            $clientHistory
        );

        $this->assertNull((new ApiClient('navikt', 'access-token', $httpClient))->getSamlId('user5'));
    }

    /**
     * @covers ::setTeamDescription
     */
    public function testCanSetTeamDescription() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [
                new Response(200, [], '{"id": 123}'),
                new Response(200, [], '{"id": 123, "name": "team-name", "description": "team-description"}'),
            ],
            $clientHistory
        );

        $this->assertInstanceOf(
            Team::class,
            (new ApiClient('navikt', 'access-token', $httpClient))->setTeamDescription('team-name', 'team description')
        );

        $this->assertCount(2, $clientHistory, 'Expected two requests');

        $get = $clientHistory[0]['request'];
        $this->assertSame('GET', $get->getMethod());
        $this->assertSame('orgs/navikt/teams/team-name', (string) $get->getUri());

        $patch = $clientHistory[1]['request'];
        $this->assertSame('PATCH', $patch->getMethod());
        $this->assertSame('teams/123', (string) $patch->getUri());

        $body = json_decode($patch->getBody()->getContents(), true);

        $this->assertSame('team description', $body['description'], 'Team description not correct');
    }

    /**
     * @covers ::setTeamDescription
     */
    public function testSetTeamDescriptionFailsWhenTeamDoesNotExist() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [
                new Response(404),
            ],
            $clientHistory
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Team does not exist');
        (new ApiClient('navikt', 'access-token', $httpClient))->setTeamDescription('team-name', 'team description');

        $this->assertCount(1, $clientHistory, 'Expected one request');
    }

    /**
     * @covers ::setTeamDescription
     */
    public function testSetTeamDescriptionFailsWhenPatchFails() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [
                new Response(200, [], '{"id": 123}'),
                new Response(403),
            ],
            $clientHistory
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to update description');
        (new ApiClient('navikt', 'access-token', $httpClient))->setTeamDescription('team-name', 'team description');

        $this->assertCount(2, $clientHistory, 'Expected two requests');
    }

    /**
     * @covers ::getRepos
     * @covers ::getNextUrl
     */
    public function testCanGetRepos() : void {
        $clientHistory = [];
        $httpClient = $this->getMockClient(
            [
                new Response(200, ['Link' => '<orgs/navikt/repos?per_page=100&page=2>; rel="next", <orgs/navikt/repos?per_page=100&page=17>; rel="last"'], '[{"id": 1}]'),
                new Response(200, ['Link' => '<orgs/navikt/repos?per_page=100&page=17>; rel="last"'], '[{"id": 2}]'),
            ],
            $clientHistory
        );

        $repos = (new ApiClient('navikt', 'access-token', $httpClient))->getRepos();

        $this->assertCount(2, $clientHistory, 'Expected two requests');
        $this->assertSame('orgs/navikt/repos?per_page=100', (string) $clientHistory[0]['request']->getUri());
        $this->assertSame('orgs/navikt/repos?per_page=100&page=2', (string) $clientHistory[1]['request']->getUri());
        $this->assertSame([['id' => 1], ['id' => 2]], $repos);
    }
}