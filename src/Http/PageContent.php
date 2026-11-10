<?php

namespace ErnestDefoe\SlowMail\Http;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The /slow-mail pages as the server sends them: a title, and never indexed (they're personal). */
class PageContent
{
    public function __construct(private readonly UrlGenerator $url, private readonly TranslatorInterface $translator)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-slow-mail.forum.page.title');
        $document->canonicalUrl = $this->url->to('forum')->route('slow-mail');
        $document->meta['robots'] = 'noindex, nofollow';

        return $document;
    }
}
