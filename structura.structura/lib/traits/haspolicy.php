<?php

namespace Structura\Structura\Traits;

/**
 * Управление доступом к полю/блоку по группам, пользователям и произвольным политикам.
 */
trait HasPolicy
{
    protected array $policy = [
        'groups' => [],
        'users'  => [],
        'policy' => [],
    ];

    /**
     * @param array{groups?: array, users?: array, policy?: array} $policy
     */
    public function setPolicy(array $policy): static
    {
        $this->policy = array_replace($this->policy, $policy);

        return $this;
    }

    public function getPolicy(): array
    {
        return $this->policy;
    }
}
