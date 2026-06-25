<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories;

use VEximweb\Plugin\RSpamd\Core\Models\SpamRuleScoreSample;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SpamRuleScoreSampleRepositoryInterface;

class SpamRuleScoreSampleRepository implements SpamRuleScoreSampleRepositoryInterface
{
    protected $model;

    public function __construct(SpamRuleScoreSample $model)
    {
        $this->model = $model;
    }

    public function create(array $data): void
    {
        $this->model->create($data);
    }

    public function batchCreate(array $samples): void
    {
        if (empty($samples)) {
            return;
        }
        
        $this->model->insert($samples);
    }
}