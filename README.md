# WP Ops Agent

A lightweight, performance-focused WordPress diagnostic plugin that analyzes and reports on database autoload bloat directly under the native Tools menu.

## Features
* **Autoload Overview:** Tracks the total number of configuration options loaded into memory on every single page request.
* **Size Calculation:** Computes the aggregate **stored option-value size** directly inside the database engine for maximum efficiency.
* **Heavyweight Identifier:** Displays a table highlighting the top 5 largest autoloaded items along with their modern Core handling status (`yes`, `on`, `auto`).

---

## Quick Start (Local Development via Docker)

To spun up your local environment quickly using Docker Compose, navigate to your root project directory containing your setup configurations and execute:

```bash
docker compose up -d
```

Once the containers initialize, open your web browser and navigate to your local dashboard address:
```text
http://localhost/wp-admin/
```
*(Note: If your local setup utilizes a different proxy configuration or specified port binding like `http://localhost:8080`, adjust your destination link accordingly.)*

Log into your local administration dashboard, navigate to **Plugins**, and activate **WP Ops Agent**.

---

## How to Run & View the Report

Once the plugin is active, it runs silently behind the scenes without introducing any background scanning overhead. To view your diagnostics data:

1. Look at the left sidebar menu of your WordPress dashboard.
2. Hover over or click on the native **Tools** configuration panel.
3. Select the sub-menu link item labeled **WP Ops Agent**.
4. The dashboard page will compute metrics instantly and render your tables.

---

## Understanding the Metrics

### 1. Stored Option-Value Size
*   **Crucial Distinction:** The metric displayed represents the physical **stored option-value size** as calculated directly inside your database engine. It does **not** reflect active PHP execution memory usage (`memory_limit`).
*   **Performance Guidelines:** WordPress core works best when the collective **stored option-value size** remains well below **800 KB**. If your summary table displays values entering multiple Megabytes (MB), it indicates that uninstalled plugins or bloated core configurations are slowing down your initial server response time (TTFB).

### 2. Autoload Status Values
Starting with modern core optimizations (WordPress 6.6+), the dashboard captures varied strategies:
* `yes` / `on`: A developer explicitly configured this specific setting to initialize across every single request.
* `auto`: The engine dynamically gauges initialization eligibility based on automated heuristic thresholds (e.g., skipping items exceeding 150 KB).

---

## Support & Configuration Requirements
* **Minimum WordPress Version:** Core 6.0+
* **Minimum System Environment:** PHP 7.4+
* **Execution Cap Structure:** Designed safely to compute massive database options tables using low-footprint `SQL LENGTH` macros to completely bypass PHP memory allocations.
