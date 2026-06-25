<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories;

use VEximweb\Plugin\RSpamd\Core\Models\EmailScoreSample;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\EmailScoreSampleRepositoryInterface;

class EmailScoreSampleRepository implements EmailScoreSampleRepositoryInterface
{
    protected $model;

    public function __construct(EmailScoreSample $model)
    {
        $this->model = $model;
    }

    public function create(array $data): void
    {
        $this->model->create($data);
    }
}