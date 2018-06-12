<?php
/**
 * kiwi-suite/database (https://github.com/kiwi-suite/database)
 *
 * @package kiwi-suite/database
 * @see https://github.com/kiwi-suite/database
 * @copyright Copyright (c) 2010 - 2017 kiwi suite GmbH
 * @license MIT License
 */

declare(strict_types=1);
namespace KiwiSuite\Database\Type\Strategy;

use Doctrine\DBAL\Types\Type;
use KiwiSuite\Database\Type\Generator\Generator;
use KiwiSuite\Database\Type\TypeConfig;

final class RuntimeStrategy
{
    public function generate(TypeConfig $typeConfig) : void
    {
        $generator = new Generator();

        foreach ($typeConfig->getTypes() as $type => $config) {
            $className = $generator->generateFullQualifiedName($type);
            if (\class_exists($className)) {
                continue;
            }

            $fileName = \tempnam(\sys_get_temp_dir(), $className . '.php.tmp.');

            \file_put_contents(
                $fileName,
                $generator->generate(
                    $type,
                    $config['baseType']
                )
            );

            /* @noinspection PhpIncludeInspection */
            require $fileName;
            \unlink($fileName);
        }

        foreach ($typeConfig->getTypes() as $type => $config) {
            Type::addType($type, $generator->generateFullQualifiedName($type));
        }
    }
}
