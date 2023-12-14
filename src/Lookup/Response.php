<?php

declare(strict_types=1);

namespace Nsq\Lookup;

use Nsq\Exception\LookupException;

final class Response
{
    /**
     * @param string[]   $channels
     * @param Producer[] $producers
     */
    public function __construct(
        public array $channels ,
        public array $producers ,
    )
    {
    }

    public static function fromJson(string $json): self
    {
        $array = json_decode($json , true , flags: JSON_THROW_ON_ERROR);

        if ( \array_key_exists('message' , $array) ) {
            throw new LookupException($array['message']);
        }
        //兼容有赞nsqlookupd升级版
        if ( \array_key_exists('status_code' , $array) && \array_key_exists('data' , $array) ) {
            return new self(
                $array['data']['channels'] ?? [] ,
                array_map([Producer::class , 'fromArray'] , $array['data']['producers']) ,
            );
        }

        return new self(
            $array['channels'] ?? [] ,
            array_map([Producer::class , 'fromArray'] , $array['producers']) ,
        );
    }
}
