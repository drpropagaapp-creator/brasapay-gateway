<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmContactMeta extends Model
{
    protected $table = 'crm_contact_meta';

    protected $fillable = ['tenant_id', 'email', 'tags', 'notes', 'updated_by'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }
}
