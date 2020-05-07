<?php declare(strict_types=1);
namespace NAVIT\GitHub\Models;

use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

/**
 * @coversDefaultClass NAVIT\GitHub\Models\Team
 */
class TeamTest extends TestCase {
    /**
     * @covers ::fromArray
     * @covers ::getId
     * @covers ::getName
     * @covers ::getSlug
     * @covers ::__construct
     */
    public function testCanCreateFromArray() : void {
        $team = Team::fromArray(['id' => 123, 'name' => 'some name', 'slug' => 'some-name']);
        $this->assertSame(123, $team->getId());
        $this->assertSame('some name', $team->getName());
        $this->assertSame('some-name', $team->getSlug());
    }

    /**
     * @covers ::fromArray
     */
    public function testCanValidateInput() : void {
        $this->expectExceptionObject(new InvalidArgumentException('Missing data element: id'));
        Team::fromArray(['name' => 'name']);
    }
}