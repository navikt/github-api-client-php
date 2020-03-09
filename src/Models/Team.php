<?php declare(strict_types=1);
namespace NAVIT\GitHub\Models;

use InvalidArgumentException;

class Team extends Model {
    /**
     * @var int
     */
    private $id;

    /**
     * @var string
     */
    private $name;

    public function __construct(int $id, string $name) {
        $this->id = $id;
        $this->name = $name;
    }

    public function getId() : int {
        return $this->id;
    }

    public function getName() : string {
        return $this->name;
    }

    public static function fromArray(array $data) : self {
        foreach (['id', 'name'] as $required) {
            if (empty($data[$required])) {
                throw new InvalidArgumentException(sprintf('Missing data element: %s', $required));
            }
        }

        return new self(
            $data['id'],
            $data['name']
        );
    }
}