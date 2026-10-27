<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $post_id
 * @property string $body
 */
final class Comment extends Model
{
    protected $table = 'demo_comments';
}
