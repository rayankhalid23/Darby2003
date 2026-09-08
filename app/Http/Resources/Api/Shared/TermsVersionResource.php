<?php

namespace App\Http\Resources\Api\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TermsVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'version_number' => $this->version_number,
            'title'          => $this->title,
            'audience'       => $this->audience,
            'status'         => $this->status,
            'published_at'   => $this->published_at,
            'articles'       => $this->whenLoaded('articles', fn () => $this->articles->map(fn ($article) => [
                'id'             => $article->id,
                'article_number' => $article->article_number,
                'title'          => $article->title,
                'body'           => $article->body,
            ])->values()),
        ];
    }
}
