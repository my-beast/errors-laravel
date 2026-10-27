<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $title
 */
final class Post extends Model
{
    protected $table = 'demo_posts';

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
