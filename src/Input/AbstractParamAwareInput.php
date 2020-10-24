<?php

/**
 * @see       https://github.com/laminas/laminas-cli for the canonical source repository
 * @copyright https://github.com/laminas/laminas-cli/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-cli/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Laminas\Cli\Input;

use InvalidArgumentException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Webmozart\Assert\Assert;

use function array_map;
use function array_walk;
use function get_debug_type;
use function in_array;
use function is_array;
use function sprintf;

// phpcs:disable WebimpressCodingStandard.Commenting.TagWithType.InvalidTypeFormat
// phpcs:disable WebimpressCodingStandard.Commenting.TagWithType.InvalidParamName
/**
 * Decorate an input instance to add a `getParam()` method.
 *
 * @internal
 */
abstract class AbstractParamAwareInput implements ParamAwareInputInterface
{
    /** @var QuestionHelper */
    protected $helper;

    /** @var InputInterface */
    protected $input;

    /** @var OutputInterface */
    protected $output;

    /** @var array<string, InputParamInterface> */
    private $params;

    /**
     * @param array<string, InputParamInterface> $params
     */
    public function __construct(InputInterface $input, OutputInterface $output, QuestionHelper $helper, array $params)
    {
        $this->input  = $input;
        $this->output = $output;
        $this->helper = $helper;
        $this->params = $params;
    }

    /**
     * Define this method in order to modify the question, if needed, before
     * prompting for an answer.
     */
    abstract protected function modifyQuestion(Question $question): void;

    /**
     * @return mixed
     * @throws InvalidArgumentException When the parameter does not exist.
     * @throws InvalidArgumentException When the parameter is of an invalid type.
     * @throws InvalidArgumentException When the parameter is required, input is
     *     non-interactive, and no value is provided.
     */
    final public function getParam(string $name)
    {
        if (! isset($this->params[$name])) {
            throw new InvalidArgumentException(sprintf('Invalid parameter name: %s', $name));
        }

        $value      = $this->input->getOption($name);
        $inputParam = $this->params[$name];

        $question = $inputParam->getQuestion();
        $this->modifyQuestion($question);

        if (
            ! $this->isParamValueProvided($inputParam, $value)
            && ! $this->input->isInteractive()
        ) {
            /** @psalm-suppress MixedAssignment */
            $value = $inputParam->getDefault();
        }

        $valueIsArray = (bool) ($inputParam->getOptionMode() & InputOption::VALUE_IS_ARRAY);
        if ($this->isParamValueProvided($inputParam, $value)) {
            $this->validateValue($value, $valueIsArray, $question->getValidator(), $name);
            return $this->normalizeValue($value, $valueIsArray, $question->getNormalizer());
        }

        if (! $this->input->isInteractive() && $inputParam->isRequired()) {
            throw new InvalidArgumentException(sprintf('Missing required value for --%s parameter', $name));
        }

        // Prepend a validator that will skip validation of empty/null values
        // when the parameter is not required.
        $originalValidator = null;
        if (! $inputParam->isRequired()) {
            $originalValidator = $this->prependSkipValidator($question);
        }

        /** @var null|bool|string|array $value */
        $value = $this->askQuestion($question, $valueIsArray, $inputParam->isRequired());

        // Reset the validator if we prepended it earlier.
        if ($originalValidator) {
            $question->setValidator($originalValidator);
        }

        // set the option value so it can be reused in chains
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $this->input->setOption($name, $value);

        return $value;
    }

    // Proxy methods implementing interface (common across symfony/console versions)
    // phpcs:disable WebimpressCodingStandard.Functions.Param.MissingSpecification, WebimpressCodingStandard.Functions.ReturnType.ReturnValue

    public function getFirstArgument(): ?string
    {
        return $this->input->getFirstArgument();
    }

    public function bind(InputDefinition $definition): void
    {
        $this->input->bind($definition);
    }

    public function validate(): void
    {
        $this->input->validate();
    }

    /**
     * @return mixed[]
     */
    public function getArguments(): array
    {
        return $this->input->getArguments();
    }

    /**
     * @param string|int $name
     */
    public function hasArgument($name): ?bool
    {
        return $this->input->hasArgument($name);
    }

    /**
     * @return mixed[]
     */
    public function getOptions(): array
    {
        return $this->input->getOptions();
    }

    public function isInteractive(): ?bool
    {
        return $this->input->isInteractive();
    }

    /**
     * @param resource $stream
     */
    public function setStream($stream): void
    {
        if (! $this->input instanceof StreamableInputInterface) {
            return;
        }
        $this->input->setStream($stream);
    }

    /**
     * @return null|resource
     */
    public function getStream()
    {
        if (! $this->input instanceof StreamableInputInterface) {
            return null;
        }
        return $this->input->getStream();
    }

    // phpcs:enable

    /**
     * @param mixed $value
     */
    private function isParamValueProvided(InputParamInterface $param, $value): bool
    {
        $mode = $param->getOptionMode();

        if ($mode & InputOption::VALUE_IS_ARRAY) {
            return ! in_array($value, [null, []], true);
        }

        return $value !== null;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeValue($value, bool $valueIsArray, ?callable $normalizer)
    {
        // No normalizer: nothing to do
        if ($normalizer === null) {
            return $value;
        }

        // Non-array value: normalize it directly
        if (! $valueIsArray && ! is_array($value)) {
            return $normalizer($value);
        }

        // Array value: map each to the normalizer
        Assert::isArray($value);
        return array_map($normalizer, $value);
    }

    /**
     * @param mixed $value
     * @throws InvalidArgumentException When an array value is expected, but not
     *     provided.
     */
    private function validateValue(
        $value,
        bool $valueIsArray,
        ?callable $validator,
        string $paramName
    ): void {
        // No validator: nothing to do
        if (! $validator) {
            return;
        }

        // Non-array value; validate it directly
        if (! $valueIsArray) {
            $validator($value);
            return;
        }

        // Array value expected, but not an array: raise an exception
        Assert::isArray($value, sprintf(
            'Option --%s expects an array of values, but received "%s";'
            . ' check to ensure the command has provided a valid default.',
            $paramName,
            get_debug_type($value)
        ));

        // Array value: validate each item in the array
        array_walk($value, $validator);
    }

    /**
     * @return mixed Returns result of asking question, or, if this is a
     *     multi-select, it loops until no more answers are provided, and retuns
     *     an array of results.
     */
    private function askQuestion(Question $question, bool $valueIsArray, bool $valueIsRequired)
    {
        if (! $valueIsArray) {
            /** @psalm-suppress MixedAssignment */
            return $this->helper->ask($this, $this->output, $question);
        }

        $validator = $question->getValidator();
        $value     = null;

        /** @var mixed[] $values */
        $values = [];

        do {
            if (null !== $value) {
                /** @psalm-suppress MixedAssignment */
                $values[] = $value;
            }

            /** @var mixed $value */
            $value = $this->helper->ask($this, $this->output, $question);

            if ($valueIsRequired && [] === $values) {
                $question->setValidator(
                    /**
                     * @psalm-template ValueType of mixed
                     * @psalm-param callable(ValueType): bool $validator
                     * @psalm-param ValueType $value
                     */
                    static function ($value) use ($validator) {
                        if (null === $value || '' === $value) {
                            return $value;
                        }

                        if (null === $validator) {
                            return $value;
                        }

                        /** @psalm-suppress MixedAssignment */
                        return $validator($value);
                    }
                );
            }
        } while (! in_array($value, [null, ''], true));

        $question->setValidator($validator);

        return $values;
    }

    private function prependSkipValidator(Question $question): ?callable
    {
        $originalValidator = $question->getValidator();
        if (null === $originalValidator) {
            return null;
        }

        $question->setValidator(
            /**
             * @psalm-template ValueType of mixed
             * @psalm-param callable(ValueType): bool $validator
             * @psalm-param ValueType $value
             */
            static function ($value) use ($originalValidator) {
                if ($value === null) {
                    return null;
                }

                return $originalValidator($value);
            }
        );

        return $originalValidator;
    }
}
