<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRisk extends Model
{
    protected $fillable = ['project_id', 'title', 'description', 'category', 'probability', 'impact', 'mitigation', 'owner_id', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['probability' => 'integer', 'impact' => 'integer'];
    }

    /** Probability x impact, 1-9. 6+ is high, 3-4 medium. */
    public function score(): int
    {
        return (int) $this->probability * (int) $this->impact;
    }

    public function level(): string
    {
        $s = $this->score();

        return $s >= 6 ? 'high' : ($s >= 3 ? 'medium' : 'low');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }
}
