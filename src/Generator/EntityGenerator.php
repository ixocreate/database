<?php
/**
 * @link https://github.com/ixocreate
 * @copyright IXOLIT GmbH
 * @license MIT License
 */

declare(strict_types=1);

namespace Ixocreate\Database\Generator;

use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Ixocreate\Schema\Type\TypeSubManager;

/**
 * Class EntityGenerator
 * @package Ixocreate\Database\Repository
 */
final class EntityGenerator extends AbstractGenerator
{
    protected static $template = '<?php
<fileHeader>
declare(strict_types=1);

<namespace>

use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;
use Ixocreate\Database\DatabaseEntityInterface;
use Ixocreate\Entity\Definition;
use Ixocreate\Entity\DefinitionCollection;
use Ixocreate\Entity\EntityInterface;
use Ixocreate\Entity\EntityTrait;
use Ixocreate\Schema\Type\TypeInterface;
<uses>

final class <className> implements EntityInterface, DatabaseEntityInterface
{
    use EntityTrait;

<properties>

<getters>

<definition>

<metadata>

}

';

    /**
     * @var string
     */
    protected static $getPropertyTemplate = '    private $<fieldName>;';

    /**
     * @var string
     */
    protected static $methodTemplate =
'    public <static>function <methodName>(<args>)<fieldType>
    {
    <methodBody>
    }';

    /**
     * @var TypeSubManager
     */
    private $typeSubManager;

    /**
     * EntityGenerator constructor.
     * @param TypeSubManager $typeSubManager
     */
    public function __construct(TypeSubManager $typeSubManager)
    {
        $this->typeSubManager = $typeSubManager;
    }

    /**
     * @param $name string
     * @param $metadata ClassMetadataInfo
     * @return string
     */
    public function generateCode(string $name, ClassMetadataInfo $metadata)
    {
        $fields = $this->getFields($metadata);

        $variables = [
            '<fileHeader>'          => $this->getFileHeader(),
            '<namespace>'           => $this->generateNamespace(),
            '<uses>'                => $this->generateUses($fields),
            '<className>'           => $this->getClassNameKiwi($name),
            '<properties>'          => $this->generateProperties($fields),
            '<getters>'             => $this->generateGetters($fields),
            '<definition>'          => $this->generateDefinition($fields),
            '<metadata>'            => $this->generateMetadata($metadata->table, $metadata->identifier, $fields),
        ];

        return \str_replace(\array_keys($variables), \array_values($variables), static::$template);
    }

    /**
     * @param ClassMetadataInfo $metadata
     * @return array
     */
    protected function getFields(ClassMetadataInfo $metadata)
    {
        $typesMap = DbalType::getTypesMap();
        $namedServiceMap = $this->typeSubManager->serviceManagerConfig()->getNamedServices();

        $typeMapping = function (array &$mapping) use ($typesMap, $namedServiceMap) {
            $typeClass = $typesMap[$mapping['type']];
            $mapping['isBaseType'] = true;
            $mapping['isRelation'] = false;

            if (\mb_strpos($typeClass, '\Ixocreate\GeneratedDatabaseType\\') === 0) {
                $typeClass = $namedServiceMap[$mapping['type']];
                $mapping['className'] = $this->getClassName($typeClass);
                $mapping['isBaseType'] = false;
            }

            $mapping['typeClass'] = $typeClass;
            if ($mapping['isBaseType']) {
                $phpType = $mapping['type'];
                $entityType = '';
                switch ($mapping['type']) {
                    case \Doctrine\DBAL\Types\Types::TEXT:
                    case \Doctrine\DBAL\Types\Types::STRING:
                    case \Doctrine\DBAL\Types\Types::BLOB:
                    case \Doctrine\DBAL\Types\Types::BINARY:
                        $phpType = 'string';
                        $entityType = 'TypeInterface::TYPE_STRING';
                        break;
                    case \Doctrine\DBAL\Types\Types::INTEGER:
                    case \Doctrine\DBAL\Types\Types::BIGINT:
                    case \Doctrine\DBAL\Types\Types::SMALLINT:
                        $phpType = 'int';
                        $entityType = 'TypeInterface::TYPE_INT';
                        break;
                    case \Doctrine\DBAL\Types\Types::FLOAT:
                        $phpType = 'float';
                        $entityType = 'TypeInterface::TYPE_FLOAT';
                        break;
                    case \Doctrine\DBAL\Types\Types::BOOLEAN:
                        $phpType = 'bool';
                        $entityType = 'TypeInterface::TYPE_BOOL';
                        break;
                    case \Doctrine\DBAL\Types\Types::JSON_ARRAY:
                    case \Doctrine\DBAL\Types\Types::JSON:
                    case \Doctrine\DBAL\Types\Types::SIMPLE_ARRAY:
                        $phpType = 'array';
                        $entityType = 'TypeInterface::TYPE_ARRAY';
                        break;
                }

                $mapping['phpType'] = $phpType;
                $mapping['entityType'] = $entityType;
            }
        };

        $fields = [];
        foreach ($metadata->fieldMappings as $mapping) {
            $typeMapping($mapping);

            $fields[$mapping['columnName']] = $mapping;
        }
        foreach ($metadata->associationMappings as $mapping) {
            $targetMetadata = $this->fullMetadata[$mapping['targetEntity']];
            $referencedColumnName = $mapping['joinColumns'][0]['referencedColumnName'];

            $newMapping = [
                'fieldName' => $mapping['fieldName'],
                'columnName' => $mapping['joinColumns'][0]['name'],
                'type' => $targetMetadata->fieldMappings[$referencedColumnName]['type'],
                'nullable' => false,
                'id' => 'false',
                'isRelation' => false,
            ];
            $typeMapping($newMapping);

            $fields[$newMapping['columnName']] = $newMapping;
        }
        return $fields;
    }

    /**
     * @param array $fields
     * @return string
     */
    private function generateUses(array $fields)
    {
        $lines = [];

        foreach ($fields as $column => $details) {
            if ($details['isBaseType']) {
                continue;
            }
            $lines[$details['type']] = 'use ' . $details['typeClass'] . ';';
        }

        if (!empty($lines)) {
            \sort($lines);
        }

        return \implode("\n", $lines);
    }

    /**
     * @param array $fields
     * @return string
     */
    private function generateProperties(array $fields)
    {
        $lines = [];

        foreach ($fields as $column => $details) {
            $variables = [
                '<fieldName>' => $column,
            ];

            $lines[] = \str_replace(\array_keys($variables), \array_values($variables), static::$getPropertyTemplate);
        }

        return \implode("\n", $lines);
    }

    /**
     * @param array $fields
     * @return string
     */
    private function generateGetters(array $fields)
    {
        $lines = [];
        foreach ($fields as $column => $details) {
            $type = ($details['isBaseType']) ? $details['phpType'] : $details['className'];

            $lines[] = $this->generateMethod(
                $column,
                null,
                $type,
                !empty($details['nullable']),
                '    return $this->' . $column . ';'
            );
        }

        return \implode("\n", $lines);
    }

    private function generateMethod($name, $args, $type, $optional, $body, $static = false)
    {
        $fieldType = '';
        if (!empty($type)) {
            $fieldType = ': ' . ($optional ? '?' : '') . $type;
        }

        $variables = [
            '<methodName>'          => $name,
            '<static>'              => ($static) ? 'static ' : '',
            '<args>'                => ($args) ? \implode(', ', $args) : '',
            '<fieldType>'           => $fieldType,
            '<methodBody>'          => $body,
        ];

        return \str_replace(\array_keys($variables), \array_values($variables), static::$methodTemplate);
    }

    /**
     * @param array $fields
     * @return string
     */
    private function generateDefinition(array $fields)
    {
        $lines = [];

        $lines[] = '    return new DefinitionCollection([';
        foreach ($fields as $column => $details) {
            $type = ($details['isBaseType']) ? $details['entityType'] : $details['className'] . '::class';

            $line = "            new Definition('" . $column . "', ";
            $line .= $type . ', ';
            $line .= (($details['nullable']) ? 'true' : 'false') . ', ';
            $line .= 'true';
            $line .= '),';

            $lines[] = $line;
        }

        $lines[] = '        ]);';

        return $this->generateMethod('createDefinitions', null, 'DefinitionCollection', false, \implode("\n", $lines), true);
    }

    private function generateMetadata(array $table, array $identifier, array $fields)
    {
        $lines = ["    \$builder->setTable('{$table['name']}');", ''];

        foreach ($fields as $column => $details) {
            $type = ($details['isBaseType']) ? "'" . $details['type'] . "'" : $details['className'] . '::serviceName()';

            $line = "        \$builder->createField('$column', $type)";
            if (\in_array($column, $identifier)) {
                $line .= '->makePrimaryKey()';
            } else {
                $line .= '->nullable(' . ($details['nullable'] ? 'true' : 'false') . ')';
            }

            $line .= '->build();';
            $lines[] = $line;
        }

        return $this->generateMethod('loadMetadata', ['ClassMetadataBuilder $builder'], '', false, \implode("\n", $lines), true);
    }

    /**
     * @return string
     */
    public function getNamespacePostfix(): string
    {
        return 'Entity\\';
    }
}
