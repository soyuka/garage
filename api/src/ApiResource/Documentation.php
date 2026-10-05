<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\ListDocumentsInput;
use App\Dto\ReadDocumentationPageInput;
use App\Dto\SearchDocumentationInput;
use App\State\ListDocumentsProcessor;
use App\State\ReadDocumentationPageProcessor;
use App\State\SearchDocumentationProcessor;

/**
 * A vehicle's attached documentation (workshop manuals, technical notes, quotes) as read-only MCP
 * tools. Uploads and imports go through the HTTP endpoints and the app:documentation:import command.
 */
#[ApiResource(
    mcp: [
        'list_documents' => new McpTool(
            title: 'List Vehicle Documents',
            description: 'List the documents attached to a vehicle — workshop manuals (e.g. Renault MR), technical notes (NT), quotes (devis) and other PDFs/images — with their kind, title, sourceRef (citation label such as "MR 361 — Transmission"), page count and download url. Use it to see what documentation exists before answering a repair question, or to find the quote linked to a planned task (plannedTaskId). Identify the vehicle by its @id (the `vehicle` argument, from list_vehicles). An unknown vehicle returns a not_found result (NOT an error). Read-only.',
            input: ListDocumentsInput::class,
            structuredContent: false,
            processor: ListDocumentsProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'search_documentation' => new McpTool(
            title: 'Search Vehicle Documentation',
            description: 'Search the text of a vehicle\'s attached documentation (workshop manuals, technical notes, quotes) and get the matching PAGES with a snippet. CONSULT THIS BEFORE answering a repair, torque, capacity or procedure question for that vehicle. Each hit carries the document title, its sourceRef and the pageLabel (the manual\'s own page number, e.g. "21A-5"; null when the page has none) — ALWAYS CITE sourceRef + pageLabel when you use a hit. The snippet is an excerpt, not the whole page: NEVER invent procedure steps, values or torques that the hits do not state; if status is "no_match", say the documentation does not cover it instead of answering from memory. Matching is case- and accent-insensitive, ranked by how many of the query words a page contains. Optionally restrict by `kind` (manual, technical_note, quote, other). Identify the vehicle by its @id (the `vehicle` argument). The full text of a hit\'s page comes from read_documentation_page (documentId + page): call it whenever the answer is not entirely inside the snippet. To SHOW the operator the page a hit comes from, then follow with show_document (documentId + page + your query as highlight). Read-only.',
            input: SearchDocumentationInput::class,
            structuredContent: false,
            processor: SearchDocumentationProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
        'read_documentation_page' => new McpTool(
            title: 'Read Vehicle Documentation Page',
            description: 'Return the FULL text of ONE page of a vehicle document (as extracted from the PDF, layout preserved so table columns stay aligned). Use it after search_documentation whenever the answer — a torque, a capacity, a clearance, a procedure step — is not entirely inside the hit\'s snippet: snippets are cut at 300 characters and tables usually fall outside them. Pass the hit\'s documentId as `document` and its `page` (the physical page number, NOT the pageLabel). Answer from the page text only and cite sourceRef + pageLabel; never complete a value the page does not state. Then call show_document with the same page and your query as highlight so the operator sees the source. An unknown document returns not_found, a page out of range or a document without extracted text (scan, image) returns invalid_input. Read-only.',
            input: ReadDocumentationPageInput::class,
            structuredContent: false,
            processor: ReadDocumentationPageProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
    ],
)]
final class Documentation
{
}
