<?php declare(strict_types=1);
namespace NAVIT\GitHub\Models;

use InvalidArgumentException;

class Team extends Model {
    private int $id;
    private string $name;
    private string $slug;

    public function __construct(int $id, string $name, string $slug) {
        $this->id   = $id;
        $this->name = $name;
        $this->slug = $slug;
    }

    public function getId() : int {
        return $this->id;
    }

    public function getName() : string {
        return $this->name;
    }

    public function getSlug() : string {
        return $this->slug;
    }

    /**
     * @param array{id:int,name:string,slug:string} $data
     * @throws InvalidArgumentException
     * @return self
     */
    public static function fromArray(array $data) : self {
        foreach (['id', 'name', 'slug'] as $required) {
            if (empty($data[$required])) {
                throw new InvalidArgumentException(sprintf('Missing data element: %s', $required));
            }
        }

        return new self(
            $data['id'],
            $data['name'],
            $data['slug']
        );
    }
}