#!/usr/bin/env bash
#
# Routing regression tests for Sheaf.
#
# Re-seeds the dev site to a known state, then asserts the nested-URL routing
# invariants — per-book slug discrimination, wrong-book 404s, section handling
# and the agreed sample URLs. Run it from the host (it uses curl + the wpenv
# wrapper):
#
#   plugins/sheaf/tools/test-routing.sh
#
# Override the site with BASE, e.g. BASE=http://localhost:8888 ...
set -u

BASE="${BASE:-http://localhost:8888}"
WPENV="${WPENV:-/usr/local/bin/wpenv}"
pass=0
fail=0

ok()  { pass=$((pass + 1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
ng()  { fail=$((fail + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }

# check_status <path> <expected-code>
check_status() {
	local code
	code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$1/")"
	if [ "$code" = "$2" ]; then ok "[$code] /$1/"; else ng "[$code, want $2] /$1/"; fi
}

# check_contains <path> <substring> <label>
check_contains() {
	if curl -s "$BASE/$1/" | grep -q "$2"; then ok "/$1/ — $3"; else ng "/$1/ — $3"; fi
}

# check_absent <path> <substring> <label> — passes when the substring is NOT present
check_absent() {
	if curl -s "$BASE/$1/" | grep -q "$2"; then ng "/$1/ — $3"; else ok "/$1/ — $3"; fi
}

echo "Seeding known state…"
"$WPENV" run cli wp eval-file wp-content/plugins/sheaf/tools/seed.php >/dev/null 2>&1

echo "Pages and book/series indexes (expect 200):"
for u in novels novels/long-war novels/long-war/embers novels/long-war/ashfall \
         novels/gearfall novels/gearfall/mainspring novels/gearfall/stormgear \
         novels/wintering novels/the-ashen-compact fiction fiction/asterism \
         fiction/asterism/ship-design about about/met title-text; do
	check_status "$u" 200
done

echo "Chapters and sections (expect 200):"
for u in novels/gearfall/mainspring/3 novels/gearfall/stormgear/prologue \
         novels/gearfall/stormgear/12-skyfire novels/gearfall/mainspring/part-i-wind-up; do
	check_status "$u" 200
done

echo "Bad paths (expect 404):"
check_status "novels/long-war/embers/nonesuch" 404
# Wrong book: this slug exists only in Embers, not Ashfall — must not leak across.
check_status "novels/long-war/ashfall/13-resignations" 404

echo "Per-book slug discrimination — five 'prologue' URLs must be five distinct posts:"
distinct="$(for u in novels/long-war/embers/prologue novels/long-war/ashfall/prologue \
                     novels/gearfall/mainspring/prologue novels/gearfall/stormgear/prologue \
                     novels/wintering/prologue; do
	curl -s "$BASE/$u/" | grep -oP 'postid-\d+' | head -1
done | sort -u | wc -l)"
if [ "$distinct" = "5" ]; then ok "5 distinct prologue posts"; else ng "expected 5 distinct prologue posts, got $distinct"; fi

echo "Each prologue breadcrumbs to its own book:"
check_contains "novels/long-war/embers/prologue"   "Embers"   "breadcrumb names Embers"
check_contains "novels/long-war/ashfall/prologue"  "Ashfall"  "breadcrumb names Ashfall"
check_contains "novels/gearfall/stormgear/prologue" "Stormgear" "breadcrumb names Stormgear"

echo "Section view carries the CSS hook:"
check_contains "novels/gearfall/mainspring/part-i-wind-up" "sheaf-section" "body class sheaf-section"

echo "Hierarchy body classes on a chapter view (series/book/chapter targeting):"
check_contains "novels/long-war/embers/1-the-cold-road" "sheaf-novels-long-war-embers-1-the-cold-road" "chapter-level path class"
check_contains "novels/long-war/embers/1-the-cold-road" "sheaf-novels-long-war" "series-level path class"
check_contains "novels/long-war/embers/1-the-cold-road" "sheaf-book-" "stable book-id class"
check_contains "novels/long-war/embers/1-the-cold-road" "sheaf-chapter-" "stable chapter-id class"
# A spin-off hung on the series Page is classed under that Page as its "book".
check_contains "novels/long-war/a-candle-for-the-drowned" "sheaf-novels-long-war-a-candle-for-the-drowned" "series spin-off chapter class"

echo "Series-level spin-off stories (a chapter hung directly on a Series Page):"
check_status   "novels/long-war/a-candle-for-the-drowned" 200
check_contains "novels/long-war/a-candle-for-the-drowned" "The Long War" "spin-off breadcrumbs to its series"

echo "Sub-page collision guard — a coda asking for the slug 'embers' must not shadow the Book:"
# The Book Page must still answer at /novels/long-war/embers (not the coda).
check_status "novels/long-war/embers" 200
check_absent "novels/long-war/embers" "Embers: A Coda" "/embers loads the Book, not the coda"
# The coda survives, reachable at the guard-rewritten slug.
check_status   "novels/long-war/embers-2" 200
check_contains "novels/long-war/embers-2" "Embers: A Coda" "coda reachable at embers-2"

echo "Data-layer checks:"
g="$("$WPENV" run cli wp eval '
$s  = get_page_by_path("novels/long-war");
$id = $s ? (int) $s->ID : 0;
$c  = \Sheaf\Books::slug_collides_with_book_subpage("embers", $id) ? "1" : "0";
$b  = ( \Sheaf\Books::unique_chapter_slug("embers", $id) !== "embers" ) ? "1" : "0";
$f  = \Sheaf\Books::slug_collides_with_book_subpage("a-candle-for-the-drowned", $id) ? "0" : "1";
echo $c . $b . $f;
' 2>/dev/null | tr -dc '0-9')"
if [ "$g" = "111" ]; then ok "guard: 'embers' collides+bumps, clean slug passes"; else ng "guard predicate expected 111, got $g"; fi

slug="$("$WPENV" run cli wp eval '
$s = get_page_by_path("novels/long-war");
$p = $s ? get_posts(["post_type"=>"sheaf_chapter","title"=>"Embers: A Coda","numberposts"=>1,"meta_key"=>"_sheaf_book","meta_value"=>$s->ID]) : [];
echo $p ? $p[0]->post_name : "none";
' 2>/dev/null | tr -dc 'a-z0-9-')"
if [ "$slug" = "embers-2" ]; then ok "coda stored with guard-rewritten slug embers-2"; else ng "coda slug expected embers-2, got $slug"; fi

n="$("$WPENV" run cli wp eval 'echo count(get_posts(["post_type"=>"sheaf_chapter","name"=>"prologue","post_status"=>"publish","numberposts"=>-1]));' 2>/dev/null | tr -dc '0-9')"
if [ "$n" = "5" ]; then ok "5 chapters stored with the slug 'prologue'"; else ng "expected 5 'prologue' slugs, got $n"; fi

w="$("$WPENV" run cli wp eval 'echo \Sheaf\Words::count_in("<p>one two three</p>[sheaf_toc]");' 2>/dev/null | tr -dc '0-9')"
if [ "$w" = "3" ]; then ok "Words::count_in strips markup/shortcodes (=3)"; else ng "Words::count_in expected 3, got $w"; fi

echo
echo "Passed: $pass   Failed: $fail"
[ "$fail" -eq 0 ]
