# ouachita.online Setup Guide

Reference implementation for a tourism and lifestyle publication using MetaManager.

## Site Profile

- **Domain:** ouachita.online
- **Purpose:** Tourism and lifestyle publication for Ouachita Parish, Louisiana
- **Content:** Area guides, attraction listings, event coverage, local business directory, blog articles

## Business Profile Configuration

Navigate to **MetaManager > Business**:

- **Business Type:** `Organization`
- **Business Name:** Ouachita Online
- **Description:** Tourism and lifestyle publication for Ouachita Parish, Louisiana
- **Address:** Monroe, LA (if applicable)

## Content Structure

### Pages (use page templates)

| Page | Template | Schema Type |
|------|----------|-------------|
| Home | (default) | WebPage |
| About | mm-about | AboutPage |
| Contact | mm-contact | ContactPage |
| Ouachita Parish Area Guide | mm-area-guide | TouristDestination |

### Destinations (mm_destination CPT)

Create a Destination for each area/attraction:

| Destination | Tourist Type | Attractions |
|-------------|-------------|-------------|
| Ouachita Parish | Cultural tourism, Outdoor recreation | Monroe, West Monroe, Kiroli Park |
| Monroe, LA | Urban tourism | Downtown, River Market |
| West Monroe, LA | Family tourism | Antique Alley, Kiroli Park |
| Kiroli Park | Outdoor recreation | Hiking, Disc Golf, Dog Park |
| Black Bayou Lake | Wildlife tourism | Birding, Photography, Kayaking |

### Events (mm_event CPT)

Create Events for local happenings with dates, locations, and pricing.

### Blog Posts (default post type)

Write articles using BlogPosting schema:
- "Top 10 Things to Do in Ouachita Parish"
- "Best Restaurants in Monroe"
- "Outdoor Adventures in West Monroe"
- "Seasonal Events Guide"

## Schema Output

The site will emit:

1. **Organization** node (site-wide) — Ouachita Online
2. **TouristDestination** nodes — Ouachita Parish, Monroe, West Monroe, etc.
3. **Event** nodes — local festivals, markets, concerts
4. **BlogPosting** nodes — articles about the area
5. **WebPage** nodes — About, Contact, Area Guide pages
6. **ItemList** nodes — directory listings

## Verification

After setup, verify schema output:

1. Visit any page and view source
2. Look for `<script type="application/ld+json">` blocks
3. Validate at https://validator.schema.org
4. Check Google Rich Results Test at https://search.google.com/test/rich-results
