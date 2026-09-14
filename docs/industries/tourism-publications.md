# Tourism Publication Industry Guide

Guide for tourism publications, area guides, and destination marketing sites using MetaManager.

## Business Profile Setup

Set your Business Profile type in **MetaManager > Business**:

| Site Type | Schema Type | Example |
|-----------|-------------|---------|
| Tourism publication | `Organization` | ouachita.online |
| Destination marketing | `Organization` | — |
| Travel blog | `Organization` | — |

## Content Types to Use

### TouristDestination (mm_destination)

Create a Destination for each area/attraction being promoted:
- Ouachita Parish
- Monroe, LA
- West Monroe, LA
- Kiroli Park
- Black Bayou Lake

**Key fields:**
- Address, Coordinates, Tourist Type, Attractions, Map URL, Booking URL

### Event (mm_event)

Create Events for local happenings:
- Local festivals
- Farmers markets
- Concerts
- Seasonal events

### Blog Posts (default post type)

Write articles about local attractions, things to do, seasonal guides. Use BlogPosting schema type.

### Area Guide (page template mm-area-guide)

Create an Area Guide page for each major region:
- Ouachita Parish Area Guide
- Monroe Area Guide

The Area Guide template automatically aggregates Destinations and Events.

### Local Business Directory

Create a page listing local businesses. Use the `[mm_sitemap]` shortcode or custom queries.

## Schema Output

When properly configured, your site will emit:

1. **Organization** node (site-wide)
2. **TouristDestination** nodes for each area/attraction
3. **Event** nodes for local happenings
4. **BlogPosting** nodes for articles
5. **ItemList** nodes on directory pages

## Example: ouachita.online Setup

1. **Business Profile:** Type = `Organization`, Name = "Ouachita Online"
2. **Pages:**
   - Home — BlogPosting default
   - About — AboutPage template
   - Contact — ContactPage template
   - Ouachita Parish Area Guide — mm-area-guide template
3. **Destinations:**
   - Ouachita Parish (main destination)
   - Monroe, LA
   - West Monroe, LA
   - Kiroli Park
   - Black Bayou Lake National Wildlife Refuge
   - Biedenharn Museum & Gardens
4. **Events:**
   - Local festivals, markets, seasonal events
5. **Blog Posts:**
   - "Top 10 Things to Do in Ouachita Parish"
   - "Best Restaurants in Monroe"
   - "Outdoor Adventures in West Monroe"
