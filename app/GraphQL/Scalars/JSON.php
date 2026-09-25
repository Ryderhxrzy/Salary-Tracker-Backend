<?php

namespace App\GraphQL\Scalars;

use GraphQL\Error\Error;
use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NullValueNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;

/** Free-form JSON (maps keyed by date, etc.). */
class JSON extends ScalarType
{
    public string $name = 'JSON';

    public ?string $description = 'Arbitrary JSON value.';

    public function serialize($value): mixed
    {
        return $value;
    }

    public function parseValue($value): mixed
    {
        return $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): mixed
    {
        if ($valueNode instanceof StringValueNode || $valueNode instanceof BooleanValueNode) {
            return $valueNode->value;
        }
        if ($valueNode instanceof IntValueNode) {
            return (int) $valueNode->value;
        }
        if ($valueNode instanceof FloatValueNode) {
            return (float) $valueNode->value;
        }
        if ($valueNode instanceof NullValueNode) {
            return null;
        }
        if ($valueNode instanceof ListValueNode) {
            $out = [];
            foreach ($valueNode->values as $node) {
                $out[] = $this->parseLiteral($node, $variables);
            }

            return $out;
        }
        if ($valueNode instanceof ObjectValueNode) {
            $out = [];
            foreach ($valueNode->fields as $field) {
                $out[$field->name->value] = $this->parseLiteral($field->value, $variables);
            }

            return $out;
        }

        throw new Error('Unsupported JSON literal.');
    }
}
