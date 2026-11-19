<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * A small recursive-descent evaluator: numbers, + - * / ^, unary minus and parentheses.
 * Nothing is ever passed to eval().
 */
final class Calculator
{
    /** @var list<string> */
    private array $tokens = [];
    private int $position = 0;

    public function evaluate(string $expression): float|int
    {
        if (!preg_match_all('/\d+(?:\.\d+)?|[-+*\/^()]|\S/', $expression, $matches) || [] === $matches[0]) {
            throw new \InvalidArgumentException('Empty expression.');
        }
        $this->tokens = $matches[0];
        $this->position = 0;
        $value = $this->sum();
        if ($this->position < \count($this->tokens)) {
            throw new \InvalidArgumentException(\sprintf('Unexpected "%s" in expression.', $this->tokens[$this->position]));
        }

        return is_finite($value) && floor($value) === $value && abs($value) < \PHP_INT_MAX ? (int) $value : round($value, 6);
    }

    private function sum(): float
    {
        $value = $this->product();
        while (\in_array($this->peek(), ['+', '-'], true)) {
            $value = '+' === $this->next() ? $value + $this->product() : $value - $this->product();
        }

        return $value;
    }

    private function product(): float
    {
        $value = $this->power();
        while (\in_array($this->peek(), ['*', '/'], true)) {
            if ('*' === $this->next()) {
                $value *= $this->power();
                continue;
            }
            $divisor = $this->power();
            if (0.0 === $divisor) {
                throw new \InvalidArgumentException('Division by zero.');
            }
            $value /= $divisor;
        }

        return $value;
    }

    private function power(): float
    {
        $base = $this->unary();

        return '^' === $this->peek() && null !== $this->next() ? $base ** $this->power() : $base;
    }

    private function unary(): float
    {
        if ('-' === $this->peek()) {
            $this->next();

            return -$this->unary();
        }

        return $this->primary();
    }

    private function primary(): float
    {
        $token = $this->next();
        if ('(' === $token) {
            $value = $this->sum();
            if (')' !== $this->next()) {
                throw new \InvalidArgumentException('Missing closing parenthesis.');
            }

            return $value;
        }
        if (null === $token || !is_numeric($token)) {
            throw new \InvalidArgumentException(\sprintf('Expected a number, got "%s".', $token ?? 'end of expression'));
        }

        return (float) $token;
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function next(): ?string
    {
        return $this->tokens[$this->position++] ?? null;
    }
}
