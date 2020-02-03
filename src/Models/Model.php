<?php declare(strict_types=1);
namespace NAVIT\GitHub\Models;

use NAVIT\GitHub\Exceptions\InvalidArgumentException;
use GuzzleHttp\Psr7\Response;

abstract class Model {
    /**
     * Create an instance from an array
     *
     * @throws InvalidArgumentException
     * @return self
     */
    abstract public static function fromArray(array $data);

    /**
     * Create an instance from an API response
     *
     * @throws InvalidArgumentException
     */
    public static function fromApiResponse(Response $response) : self {
        return static::fromArray(json_decode($response->getBody()->getContents(), true));
    }
}