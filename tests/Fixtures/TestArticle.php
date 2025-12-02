<?php

namespace Brim\Tests\Fixtures;

use Brim\Contracts\Embeddable;
use Brim\Traits\HasEmbeddings;
use Illuminate\Database\Eloquent\Model;

class TestArticle extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'articles';

    protected $fillable = [
        'title',
        'content',
        'category',
    ];

    protected bool $brimAutoSync = false;

    public function toEmbeddableText(): string
    {
        return "Title: {$this->title}\n\nContent: {$this->content}";
    }

    public function getEmbeddingNamespace(): ?string
    {
        return $this->category;
    }
}
