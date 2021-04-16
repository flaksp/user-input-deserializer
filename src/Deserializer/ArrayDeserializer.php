<?php

declare(strict_types=1);

namespace Flaksp\UserInputProcessor\Deserializer;

use Closure;
use Flaksp\UserInputProcessor\ConstraintViolation\ArrayIsTooLong;
use Flaksp\UserInputProcessor\ConstraintViolation\ArrayIsTooShort;
use Flaksp\UserInputProcessor\ConstraintViolation\ConstraintViolationCollection;
use Flaksp\UserInputProcessor\ConstraintViolation\WrongPropertyType;
use Flaksp\UserInputProcessor\Exception\ValidationError;
use Flaksp\UserInputProcessor\JsonPointer;
use LogicException;

final class ArrayDeserializer
{
    public function deserialize(
        mixed $data,
        JsonPointer $pointer,
        Closure $deserializer,
        bool $isNullable = false,
        int $minItems = null,
        int $maxItems = null,
    ): ?array {
        if (null !== $minItems && null !== $maxItems && $minItems > $maxItems) {
            throw new LogicException('Min items constraint can not be bigger than max items');
        }

        if (null === $data && $isNullable) {
            return null;
        }

        $violations = new ConstraintViolationCollection();

        if (!\is_array($data)) {
            $violations[] = WrongPropertyType::guessGivenType(
                $pointer,
                $data,
                [WrongPropertyType::JSON_TYPE_ARRAY]
            );

            throw new ValidationError($violations);
        }

        if (ObjectDeserializer::isAssocArray($data)) {
            $violations[] = new WrongPropertyType(
                $pointer,
                WrongPropertyType::JSON_TYPE_OBJECT,
                [WrongPropertyType::JSON_TYPE_ARRAY]
            );

            throw new ValidationError($violations);
        }

        if (null !== $minItems && \count($data) < $minItems) {
            $violations[] = new ArrayIsTooShort(
                $pointer,
                $minItems
            );
        }

        if (null !== $maxItems && \count($data) > $maxItems) {
            $violations[] = new ArrayIsTooLong(
                $pointer,
                $maxItems
            );
        }

        if ($violations->isNotEmpty()) {
            throw new ValidationError($violations);
        }

        foreach ($data as $index => $indexedData) {
            try {
                $data[$index] = $deserializer(
                    $data[$index],
                    JsonPointer::append($pointer, $index)
                );
            } catch (ValidationError $e) {
                $violations->addAll($e->getViolations());
            }
        }

        if ($violations->isNotEmpty()) {
            throw new ValidationError($violations);
        }

        return $data;
    }
}
