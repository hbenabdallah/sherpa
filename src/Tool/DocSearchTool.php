<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Rag\DocSearch;

#[AsTool(
    name: 'doc_search',
    description: "Search the project's documentation (README, docs/, ADRs, guides — Markdown, text, reStructuredText, AsciiDoc, HTML) and get the relevant passages with their source. Use it for rules, procedures, architecture decisions and how-tos, before answering from memory; use project_grep for code. Ask in plain words: with an embedding model configured the search matches meaning, across wordings and languages, as well as exact terms; without one it matches keywords only, so use the words the documentation is likely to use.",
    permission: Permission::AUTO,
)]
class DocSearchTool
{
    public function __construct(private readonly DocSearch $search) {}

    public function __invoke(
        #[Param('What to look for, as a question or keywords')] string $query,
    ): string {
        if (trim($query) === '') {
            return 'Give a question, or keywords to look for in the documentation.';
        }

        return $this->search->search($query);
    }
}
