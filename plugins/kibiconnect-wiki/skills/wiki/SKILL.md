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

## Existing Handbooks

The wiki contains the following product handbooks:

| Produkt | URL | Beschreibung |
|---------|-----|--------------|
| **Kibi Connect** | `https://weslink.kibi.de/wiki/kibi-connect` | Kunden-Handbuch fuer die KibiConnect Plattform (Kommunikation, Gruppen, Posts, Aufgaben, Wiki, Chat) |
| **Kibi SCADA** | `https://weslink.kibi.de/wiki/kibi-scada` | Kunden-Handbuch fuer die Kibi SCADA Plattform (Geraeteueberwachung, Monitoring, Alarme) |
| **MenuMobil** | `https://weslink.kibi.de/wiki/menumobil-inductline` | ServicePartner/Hersteller-Handbuch fuer MenuMobil-Geraete (InductLine, ContactLine) |

These are nested under the parent page "Kunden Handbucher" (ID: `01hwq76hz1hrwf3z4wmj77mfh2`).

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

## Screenshots for Wiki Pages

Wiki pages can include images via the `imageResize` TipTap node. The workflow for adding screenshots:

### 1. Prepare Demo Data

Before taking screenshots, ensure the local environment has clean, representative demo data:
- Use `./vendor/bin/sail artisan kibi:setup --demo` for base data
- Create additional demo records via factories or seeders if needed
- **Demo data rules:**
  - Use realistic but clearly fictional names (e.g. "Pflegeheim Sonnenschein", "Caterer Musterstadt")
  - No real customer data, no real IP addresses, no real email addresses
  - Ensure data looks "full" and representative (not empty lists)
  - Use varied but plausible values (temperatures, times, device names)

### 2. Take Screenshots with Playwright

Use the Playwright MCP browser tools for automated screenshots:

```
1. Login via test-login: ./vendor/bin/sail artisan app:test-login
2. Navigate to the target page via browser_navigate
3. Wait for page load via browser_wait_for
4. IMPORTANT: Dismiss any overlays, modals, or toast notifications before capturing
5. Use browser_take_screenshot with appropriate viewport
```

**Screenshot quality checklist:**
- No unwanted overlays, modals, tooltips, or cookie banners visible
- Data in the screenshot is clean demo data (no real data)
- The relevant UI area is clearly visible and not cut off
- Use a consistent viewport size (e.g. 1920x1080)
- Crop to the relevant area when possible (use element screenshots with `ref`)
- Dark/light mode consistent across all screenshots

### 3. Upload Images via API

Upload images via `POST /media/upload` (multipart/form-data). **Always provide `wiki_page_id`** so the file is stored in the wiki page's media folder (not the root).

```python
import mimetypes

def upload_image(file_path, wiki_page_id=None):
    """Upload an image file and return its public URL."""
    boundary = "----WikiUploadBoundary"
    filename = file_path.split("/")[-1]
    mime_type = mimetypes.guess_type(filename)[0] or "image/png"

    with open(file_path, "rb") as f:
        file_data = f.read()

    body = b""
    # file field
    body += f"--{boundary}\r\n".encode()
    body += f'Content-Disposition: form-data; name="file"; filename="{filename}"\r\n'.encode()
    body += f"Content-Type: {mime_type}\r\n\r\n".encode()
    body += file_data
    body += b"\r\n"
    # wiki_page_id field
    if wiki_page_id:
        body += f"--{boundary}\r\n".encode()
        body += f'Content-Disposition: form-data; name="wiki_page_id"\r\n\r\n'.encode()
        body += f"{wiki_page_id}\r\n".encode()
    body += f"--{boundary}--\r\n".encode()

    req = urllib.request.Request(
        f"{BASE_URL}/media/upload", data=body,
        headers={
            "Authorization": f"Bearer {API_KEY}",
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Accept": "application/json",
            "User-Agent": UA,
        },
        method="POST"
    )
    try:
        with urllib.request.urlopen(req) as resp:
            result = json.loads(resp.read().decode("utf-8"))
            print(f"OK: Uploaded {filename}\n  URL: {result['url']}\n  ID: {result['id']}")
            return result["url"]
    except urllib.error.HTTPError as e:
        print(f"ERROR {e.code}: Upload {filename}")
        print(e.read().decode("utf-8")[:500])
        return None

def image(src, alt="", width=None):
    """TipTap imageResize node for embedding uploaded images."""
    attrs = {"src": src, "alt": alt, "title": None}
    if width:
        attrs["width"] = width
    return {"type": "imageResize", "attrs": attrs}
```

Add both `upload_image()` and `image()` helpers to your `wiki_helpers.py` when screenshots are needed.

**Typical workflow:**
```python
# 1. Upload screenshot (always attach to wiki page)
url = upload_image("/tmp/screenshot-dashboard.png", wiki_page_id="01kj4q9q9x...")
# 2. Embed in TipTap content
image(url, "Dashboard overview", width=800)
```

### 4. Image Placement

Place images directly after the heading or paragraph they illustrate:
```python
heading(2, text("Dashboard-Übersicht")),
paragraph(text("Das Dashboard zeigt alle relevanten Informationen auf einen Blick:")),
image("https://weslink.kibi.de/storage/media/...", "Dashboard Übersicht", width=800),
```

## Reference Files

For detailed TipTap node schemas and API endpoint specifications, load files from `references/`:
- `references/tiptap-nodes.json` - All supported TipTap node types with their attributes
- `references/api-endpoints.json` - Wiki API endpoints, authentication, known parent pages
