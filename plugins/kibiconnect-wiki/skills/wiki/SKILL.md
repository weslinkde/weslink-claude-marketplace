---
name: wiki
description: "Create and manage documentation pages on the Weslink KibiConnect Wiki. Use when asked to write wiki pages, update documentation, or manage the KibiConnect handbook."
---

# KibiConnect Wiki Documentation

Create, update, and manage wiki pages on the Weslink KibiConnect platform using the Wiki API.

## API Credentials

The API key is stored in the user's Claude memory directory. Read it from:
```
~/.claude/projects/*/memory/weslink-wiki-api.md
```

If not found, ask the user for the API key.

## Critical: Cloudflare Protection

**All API requests MUST include a browser-like User-Agent header.** Without it, Cloudflare returns a 403 error (Error 1010). Always use:

```
User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36
```

## Python Helper Pattern

For creating wiki content, generate a Python helper module in `/tmp/wiki_helpers.py`. This is the recommended approach for creating TipTap JSON content:

```python
import json
import urllib.request

API_KEY = "your-api-key-here"
BASE_URL = "https://weslink.kibi.de/api/v1"
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"

# Inline nodes
def text(t, marks=None):
    node = {"type": "text", "text": t}
    if marks:
        node["marks"] = marks
    return node

def bold(t):
    return text(t, [{"type": "bold"}])

def italic(t):
    return text(t, [{"type": "italic"}])

def code_mark(t):
    return text(t, [{"type": "code"}])

def link(t, href):
    return text(t, [{"type": "link", "attrs": {"href": href}}])

# Block nodes
def paragraph(*children):
    node = {"type": "paragraph", "attrs": {"class": None, "style": None, "textAlign": "start"}}
    if children:
        node["content"] = list(children)
    return node

def heading(level, *children):
    return {
        "type": "heading",
        "attrs": {"class": None, "style": None, "textAlign": "start", "id": None, "level": level},
        "content": list(children)
    }

def bullet_list(*items):
    """Items can be strings or lists of text nodes"""
    li_nodes = []
    for item in items:
        if isinstance(item, str):
            li_nodes.append({"type": "listItem", "attrs": {"class": None, "style": None}, "content": [paragraph(text(item))]})
        elif isinstance(item, list):
            li_nodes.append({"type": "listItem", "attrs": {"class": None, "style": None}, "content": [paragraph(*item)]})
        else:
            li_nodes.append({"type": "listItem", "attrs": {"class": None, "style": None}, "content": [paragraph(item)]})
    return {"type": "bulletList", "attrs": {"class": None, "style": None}, "content": li_nodes}

def ordered_list(*items):
    li_nodes = []
    for item in items:
        if isinstance(item, str):
            li_nodes.append({"type": "listItem", "attrs": {"class": None, "style": None}, "content": [paragraph(text(item))]})
        elif isinstance(item, list):
            li_nodes.append({"type": "listItem", "attrs": {"class": None, "style": None}, "content": [paragraph(*item)]})
        else:
            li_nodes.append({"type": "listItem", "attrs": {"class": None, "style": None}, "content": [paragraph(item)]})
    return {"type": "orderedList", "attrs": {"class": None, "style": None}, "content": li_nodes}

def callout(ctype, *content_nodes):
    return {"type": "callout", "attrs": {"type": ctype}, "content": list(content_nodes)}

def table_row(*cells, header=False):
    cell_type = "tableHeader" if header else "tableCell"
    row_cells = []
    for c in cells:
        if isinstance(c, str):
            row_cells.append({"type": cell_type, "attrs": {"colspan": 1, "rowspan": 1, "colwidth": None}, "content": [paragraph(text(c))]})
        elif isinstance(c, list):
            row_cells.append({"type": cell_type, "attrs": {"colspan": 1, "rowspan": 1, "colwidth": None}, "content": [paragraph(*c)]})
        else:
            row_cells.append({"type": cell_type, "attrs": {"colspan": 1, "rowspan": 1, "colwidth": None}, "content": [paragraph(c)]})
    return {"type": "tableRow", "content": row_cells}

def table(*rows):
    return {"type": "table", "content": list(rows)}

def details(summary_text, *content_nodes):
    return {
        "type": "details",
        "content": [
            {"type": "detailsSummary", "content": [paragraph(text(summary_text))]},
            {"type": "detailsContent", "content": list(content_nodes)}
        ]
    }

def see_also_item(page_id, label):
    return {"type": "seeAlsoItem", "attrs": {"pageId": page_id}, "content": [paragraph(text(label))]}

def see_also(*items):
    return {"type": "seeAlso", "content": list(items)}

def doc(*nodes):
    return {"type": "doc", "content": list(nodes)}

# API functions
def create_page(title, content_doc, parent_id=None, status="published"):
    payload = {"title": title, "content": json.dumps(content_doc), "status": status}
    if parent_id:
        payload["parent_id"] = parent_id
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        f"{BASE_URL}/wiki", data=data,
        headers={"Authorization": f"Bearer {API_KEY}", "Content-Type": "application/json", "Accept": "application/json", "User-Agent": UA},
        method="POST"
    )
    try:
        with urllib.request.urlopen(req) as resp:
            body = json.loads(resp.read().decode("utf-8"))
            page_id = body["data"]["id"]
            slug = body["data"]["slug"]
            print(f"OK: {title}\n  ID: {page_id}\n  Slug: {slug}")
            return page_id
    except urllib.error.HTTPError as e:
        print(f"ERROR {e.code}: {title}")
        print(e.read().decode("utf-8")[:500])
        return None

def update_page(page_id, title=None, content_doc=None, status=None):
    payload = {}
    if title: payload["title"] = title
    if content_doc: payload["content"] = json.dumps(content_doc)
    if status: payload["status"] = status
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        f"{BASE_URL}/wiki/{page_id}", data=data,
        headers={"Authorization": f"Bearer {API_KEY}", "Content-Type": "application/json", "Accept": "application/json", "User-Agent": UA},
        method="PUT"
    )
    try:
        with urllib.request.urlopen(req) as resp:
            json.loads(resp.read().decode("utf-8"))
            print(f"OK: Updated {page_id}")
            return True
    except urllib.error.HTTPError as e:
        print(f"ERROR {e.code}: {page_id}")
        print(e.read().decode("utf-8")[:500])
        return False
```

## Workflow

1. **Read API key** from memory directory
2. **Write helper module** to `/tmp/wiki_helpers.py` with the API key filled in
3. **Build content** using the helper functions
4. **Create/update pages** via the API functions

## Content Guidelines

- Use `heading(1, ...)` for main sections, `heading(2, ...)` for subsections
- Use `callout("info", ...)` for tips, `callout("warning", ...)` for important notes, `callout("danger", ...)` for critical warnings
- Use `table()` with `table_row(..., header=True)` for the first row
- Use `bullet_list()` for unordered lists and `ordered_list()` for step-by-step instructions
- Use `details()` for collapsible sections
- Use `see_also()` with `see_also_item(page_id, label)` to link related pages
- For mixed inline content (bold + text), pass a list: `[bold("Label: "), text("value")]`

## URL Structure

Wiki pages use **flat slugs**, not nested paths:
- Correct: `https://weslink.kibi.de/wiki/my-page-slug`
- Wrong: `https://weslink.kibi.de/wiki/parent-page/my-page-slug`

Parent-child relationships are managed via `parent_id`, not URL paths.

## Handbook Structure Pattern

When creating multi-page handbooks:
1. Create a main overview page as the parent
2. Create child pages with `parent_id` pointing to the main page
3. Update the main page with `seeAlso` links to all children
4. Use consistent naming and structure across pages

## Reference Files

For detailed TipTap node schemas and API endpoint specifications, load files from `references/`:
- `references/tiptap-nodes.json` - All supported TipTap node types with their attributes
- `references/api-endpoints.json` - Wiki API endpoints, authentication, known parent pages
