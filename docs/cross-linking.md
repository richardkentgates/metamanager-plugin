# Cross-Linking Guide

How schema types reference each other in MetaManager's JSON-LD output.

## The @id Convention

Every schema node gets an `@id` based on the post's permalink:

```
https://example.com/trip/crab-island-pontoon/#touristtrip
https://example.com/vessel/barletta-cabrio/#vehicle
https://example.com/destination/crab-island/#touristdestination
https://example.com/course/cpr-certification/#course
```

The `#fragment` is the lowercase schema type. This allows nodes to reference each other without duplicating data.

## Cross-References

### Trip → Vessel

When a Trip has a linked Vessel (`_mm_vessel_id` post meta), the Trip's JSON-LD includes:

```json
{
  "@type": "TouristTrip",
  "vehicle": { "@id": "https://example.com/vessel/barletta-cabrio/#vehicle" }
}
```

The Vessel node is emitted separately on its own page. Consumers resolve the `@id` reference.

### Trip → Destination

When a Trip has a linked Destination (`_mm_destination_id` post meta), the Trip's JSON-LD includes:

```json
{
  "@type": "TouristTrip",
  "itinerary": {
    "@type": "ItemList",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "item": { "@id": "https://example.com/destination/crab-island/#touristdestination" } }
    ]
  }
}
```

### Destination → Attractions

Destinations list their attractions inline (not @id references):

```json
{
  "@type": "TouristDestination",
  "includesAttraction": [
    { "@type": "TouristAttraction", "name": "Crab Island Sandbar" },
    { "@type": "TouristAttraction", "name": "Destin Harbor" }
  ]
}
```

### All Content → WebPage

Every content node references its parent WebPage:

```json
{
  "@type": "TouristTrip",
  "isPartOf": { "@id": "https://example.com/trip/crab-island-pontoon/#webpage" }
}
```

### All Content → Organization

Content types reference the site-wide Organization node:

```json
{
  "@type": "TouristTrip",
  "provider": { "@id": "https://example.com/#organization" }
}
```

## Adding New Cross-References

To add a new cross-reference between types:

1. **Save the relationship as post meta** (e.g., `_mm_vessel_id`)
2. **Add a metabox selector** in `render_meta_box()` (search/select/clear pattern)
3. **Add save handling** in `save_meta()`
4. **Add @id reference** in `add_content_node()` in `MM_Mod_Schema`
5. **Update the single template** to display the linked content

See the existing Vessel and Destination linking implementations for the pattern.
