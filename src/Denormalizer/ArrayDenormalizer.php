<?php

declare(strict_types=1);

namespace Spiks\UserInputProcessor\Denormalizer;

use Closure;
use LogicException;
use Psr\Log\LoggerInterface;
use Spiks\UserInputProcessor\ConstraintViolation\ArrayIsTooLong;
use Spiks\UserInputProcessor\ConstraintViolation\ArrayIsTooShort;
use Spiks\UserInputProcessor\ConstraintViolation\ConstraintViolationCollection;
use Spiks\UserInputProcessor\ConstraintViolation\WrongPropertyType;
use Spiks\UserInputProcessor\Exception\ValidationError;
use Spiks\UserInputProcessor\Pointer;

/**
 * Denormalizer for fields where indexed array (lists) is expected.
 *
 * It will fail if associative array passed. Use {@see ObjectDenormalizer} instead.
 */
final class ArrayDenormalizer
{
    public function __construct(
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Validates and denormalizes passed data.
     *
     * It expects `$data` to be array type, but also accepts additional validation requirements.
     *
     * @param mixed                          $data         Data to validate and denormalize
     * @param Pointer                        $pointer      Pointer containing path to current field
     * @param Closure(mixed, Pointer): mixed $denormalizer Denormalizer function that will be called for each array entry.
     *                                                     First parameter of the function will contain value of the entry.
     *                                                     The second one will contain {@see Pointer} for this entry.
     * @param int|null                       $minItems     Minimum amount of entries in passed array
     * @param int|null                       $maxItems     Maximum amount of entries in passed array
     *
     * @throws ValidationError If `$data` does not meet the requirements of the denormalizer
     *
     * @return array The same array as `$data`, but modified by `$denormalizer` function applied to each array entry
     */
    public function denormalize(
        mixed $data,
        Pointer $pointer,
        Closure $denormalizer,
        int $minItems = null,
        int $maxItems = null,
    ): array {
        if (null !== $minItems && null !== $maxItems && $minItems > $maxItems) {
            throw new LogicException('Min items constraint can not be bigger than max items');
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

        if (!self::isIndexedArray($data)) {
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
            if (null !== $this->logger) {
                $this->logger->info(
                    sprintf(
                        'Field %s contains %d constraint violations',
                        $pointer->toString(),
                        $violations->count()
                    ),
                    [
                        'field' => $pointer->toString(),
                        'violations' => $violations->toArray(),
                    ]
                );
            }

            throw new ValidationError($violations);
        }

        $processedData = [];

        foreach ($data as $index => $indexedData) {
            try {
                $processedIndex = $denormalizer(
                    $indexedData,
                    Pointer::append($pointer, $index)
                );

                $processedData[$index] = $processedIndex;
            } catch (ValidationError $e) {
                $violations->addAll($e->getViolations());
            }
        }

        if ($violations->isNotEmpty()) {
            throw new ValidationError($violations);
        }

        return $processedData;
    }

    private static function isIndexedArray(array $array): bool
    {
        return 0 === \count($array) || array_keys($array) === range(0, \count($array) - 1);
    }
}
