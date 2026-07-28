<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Session
{
    public function __construct(
        private string $token
    ) {
        $this->token = $this->normalizeToken($this->token);
    }

    public function get(): array
    {
        $state = get_transient("cjw_wizard_{$this->token}");

        return is_array($state) ? $state : [
            'step' => 0,
            'data' => [],
        ];
    }

    public function save(array $state): void
    {
        set_transient("cjw_wizard_{$this->token}", $state, HOUR_IN_SECONDS * 6);
    }

    public function reset(): void
    {
        delete_transient("cjw_wizard_{$this->token}");
    }

    public function getToken(): string
    {
        return $this->token;
    }

    private function normalizeToken(string $token): string
    {
        $token = sanitize_key($token);

        if ($token !== '') {
            return $token;
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (Exception) {
            return sanitize_key(wp_generate_password(32, false));
        }
    }
}
