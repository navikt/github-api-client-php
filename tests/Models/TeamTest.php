<?php declare(strict_types=1);
namespace NAVIT\GitHub\Models;

use NAVIT\GitHub\Exceptions\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass NAVIT\GitHub\Models\Team
 */
class TeamTest extends TestCase {
    /**
     * @covers ::fromArray
     * @covers ::getId
     * @covers ::getName
     * @covers ::__construct
     */
    public function testCanCreateFromArray() : void {
        $team = Team::fromArray(['id' => 123, 'name' => 'some-name']);
        $this->assertSame(123, $team->getId());
        $this->assertSame('some-name', $team->getName());
    }

    /**
     * @covers ::fromArray
     */
    public function testCanValidateInput() : void {
        $this->expectExceptionObject(new InvalidArgumentException('Missing data element: id'));
        Team::fromArray(['name' => 'name']);
    }
}