<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class UpdateGeneralDisableProfile extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.enable_profile', true);
    }
}