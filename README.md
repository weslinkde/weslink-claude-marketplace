# Weslink Claude Code Marketplace

Internal [Claude Code](https://docs.anthropic.com/en/docs/claude-code) plugin marketplace for Weslink projects.

## Installation

Add this marketplace once in Claude Code:

```
/plugin marketplace add weslinkde/weslink-claude-marketplace
```

Then install individual plugins:

```
/plugin install edifact@weslink-claude-marketplace
```

Other useful commands:

```
/plugin list
/plugin update edifact
/plugin uninstall edifact
/plugin marketplace update
```

## Available Plugins

### edifact

EDIFACT INVOIC parsing context for German telecom providers. Gives Claude deep knowledge of the INVOIC segment specifications for Telekom, Vodafone, and O2/Telefónica – so you don't have to re-explain the format every session.

**Usage:** Type `/edifact` in Claude Code to activate the skill, or simply mention EDIFACT, segment names (IMD, MOA, DTM, RFF, ...) – Claude will pick it up automatically.

### kibiconnect-wiki

KibiConnect Wiki API integration for creating and managing documentation pages. Provides the complete TipTap JSON content format, API endpoints, and a Python helper pattern for programmatic page creation.

**Usage:** Type `/wiki` in Claude Code to activate the skill. Use when creating handbooks, updating wiki documentation, or managing page hierarchies on the Weslink KibiConnect wiki.

**Key features:**
- TipTap JSON node reference (headings, tables, callouts, lists, seeAlso links)
- Python helper module pattern for batch page creation
- Cloudflare bypass via required User-Agent header
- Handbook structure pattern (parent + child pages with seeAlso)

## Adding a New Plugin

```
plugins/
  my-plugin/
    .claude-plugin/
      plugin.json
    skills/
      skill-name/
        SKILL.md
        references/   (optional – large reference files loaded on demand)
    commands/         (optional)
    agents/           (optional)
```

Register it in `.claude-plugin/marketplace.json` under `plugins`.
