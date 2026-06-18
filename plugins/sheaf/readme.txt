=== Sheaf ===
Contributors: sheaf
Requires at least: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later

Publish novels as one chapter per post, organised into books and series.

== Description ==

Sheaf lets a novelist publish a book as a series of posts — one chapter each —
gathered under a book "main page". Books and series are ordinary, hand-authored
WordPress Pages (so a series can also hold non-book content), and their page
hierarchy gives you nesting, URLs, and breadcrumbs for free.

Model
* Chapter — the `sheaf_chapter` custom post type. Belongs to exactly one book
  (stored as the book Page's ID). Reading order uses the core "Order" field
  (menu_order), never the chapter number, so prologues and interludes sort
  naturally.
* Book / Series / World — ordinary hierarchical Pages you build yourself.
* Chapter URLs nest under the book page, e.g.
  /novels/long-war/embers/13-resignations.

Display (all opt-in except the chapter breadcrumb)
* `[sheaf_toc]` / "Sheaf: Table of Contents" block — a book's chapters in order.
  Auto-detects the book on a book page or chapter; override with
  `[sheaf_toc book="123"]` or `[sheaf_toc book="page-slug"]`.
* `[sheaf_breadcrumbs]` / "Sheaf: Breadcrumbs" block — the hierarchy trail.
* Single chapter views automatically show breadcrumbs (filterable via
  `sheaf_auto_breadcrumbs`).

== Roadmap ==
* REST endpoints driving chapter-to-chapter infinite scroll.
* Addressable text versions that comments can reference and link to.

== Changelog ==

= 0.1.0 =
* Initial scaffold: chapter CPT, book linkage + ordering, nested URLs,
  breadcrumbs, and the TOC/breadcrumbs shortcodes and blocks.
