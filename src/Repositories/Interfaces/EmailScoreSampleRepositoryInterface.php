<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces;

interface EmailScoreSampleRepositoryInterface
{
    public function create(array $data): void;
}