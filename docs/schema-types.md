# Schema Type Reference

MetaManager supports the following schema.org types. Each type can be assigned to posts via the Schema admin tab (default per post type) or via the per-post SEO metabox override.

## Supported Types

### Page Types

| Type | Use For | CPT/Template |
|------|---------|--------------|
| `WebPage` | Generic pages, default for `page` post type | Default for pages |
| `AboutPage` | About page with business info | Page template `mm-about` |
| `ContactPage` | Contact page with business contact data | Page template `mm-contact` |
| `ProfilePage` | Author archive pages | Auto-assigned |
| `Calendar` | Event calendar page | Page template `mm-calendar` |
| `FAQPage` | FAQ pages with Q&A pairs | CPT `mm_faq_page` |
| `SearchResultsPage` | Search results | Auto-assigned |

### Content Types

| Type | Use For | CPT/Template |
|------|---------|--------------|
| `BlogPosting` | Blog posts | Default for `post` post type |
| `HowTo` | Step-by-step guides | CPT `mm_how_to` |
| `Event` | Events with dates, location, pricing | CPT `mm_event` |
| `Service` | Services offered by a business | CPT `mm_service` |
| `Product` | Products (WooCommerce compatible) | Override on any post type |

### Tourism Types

| Type | Use For | CPT/Template |
|------|---------|--------------|
| `TouristTrip` | Bookable tours, charters, experiences | CPT `mm_trip` |
| `TouristDestination` | Places/areas being promoted | CPT `mm_destination` |

### Vehicle Types

| Type | Use For | CPT/Template |
|------|---------|--------------|
| `Vehicle` | Boats, cars, vessels | CPT `mm_vessel` |

### Education Types

| Type | Use For | CPT/Template |
|------|---------|--------------|
| `Course` | Educational courses, certifications | CPT `mm_course` |

---

## TouristTrip

**Schema.org:** `Thing > Intangible > Trip > TouristTrip`

A bookable tour, charter, or experience. NOT an Event — a Trip is a standing offering available on any date. An Event happens on specific dates.

**When to use:** Charter boat trips, parasailing adventures, guided tours, boat rentals, dolphin cruises, fishing charters, any bookable tourism experience.

**Fields:**

| Field | Schema Property | Required | Description |
|-------|----------------|----------|-------------|
| Departure Location | `tripOrigin` > Place > name | Yes | Boarding/departure location name |
| Departure Address | `tripOrigin` > Place > address | No | Full address |
| Departure Latitude | `tripOrigin` > Place > geo > latitude | No | GPS latitude |
| Departure Longitude | `tripOrigin` > Place > geo > longitude | No | GPS longitude |
| Destination | `itinerary` > ItemList > TouristAttraction | No | Primary destination name |
| Duration (display) | (supplement) | No | Human-readable duration |
| Duration (ISO 8601) | `estimatedDuration` | No | ISO 8601 duration (e.g. PT4H) |
| Max Passengers | `maximumAttendeeCapacity` | No | Maximum passengers/guests |
| Price | `offers` > Offer > price | No | Numeric or range |
| Currency | `offers` > Offer > priceCurrency | No | ISO 4217 (defaults to USD) |
| Booking URL | `offers` > Offer > url | No | Link to book this trip |
| Departure Time | `departureTime` | No | Typical departure time |
| Arrival Time | `arrivalTime` | No | Typical return/arrival time |
| Tourist Type | `touristType` | No | Comma-separated audience types |
| What's Included | (supplement) | No | What the trip includes |

**Relationships:**
- Can link to a **Vessel** (`_mm_vessel_id` post meta) — references vessel's Vehicle @id in JSON-LD
- Can link to a **Destination** (`_mm_destination_id` post meta) — references destination's TouristDestination @id in JSON-LD

**JSON-LD output:**
```json
{
  "@type": "TouristTrip",
  "@id": "https://example.com/trip/crab-island-pontoon/#touristtrip",
  "name": "4 Hour Crab Island Pontoon Charter",
  "description": "...",
  "tripOrigin": {
    "@type": "Place",
    "name": "Harborwalk Village, Destin",
    "address": "10 Harbor Blvd, Destin FL 32541",
    "geo": { "@type": "GeoCoordinates", "latitude": 30.3935, "longitude": -86.5085 }
  },
  "itinerary": {
    "@type": "ItemList",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "item": { "@id": "https://example.com/destination/crab-island/#touristdestination" } }
    ]
  },
  "estimatedDuration": "PT4H",
  "maximumAttendeeCapacity": 13,
  "touristType": ["Family tourism", "Water sports"],
  "offers": { "@type": "Offer", "price": "350", "priceCurrency": "USD", "url": "https://example.com/book/" },
  "vehicle": { "@id": "https://example.com/vessel/barletta-cabrio/#vehicle" },
  "provider": { "@id": "https://example.com/#organization" }
}
```

---

## TouristDestination

**Schema.org:** `Thing > Place > TouristDestination`

A place or area being promoted. Contains or is colocated with TouristAttractions.

**When to use:** Geographic areas, tourist attractions, landmarks, beaches, parks, cities being promoted on a tourism site.

**Fields:**

| Field | Schema Property | Required | Description |
|-------|----------------|----------|-------------|
| Address | `address` | No | Location address |
| Latitude | `geo` > GeoCoordinates > latitude | No | GPS latitude |
| Longitude | `geo` > GeoCoordinates > longitude | No | GPS longitude |
| Tourist Type | `touristType` | No | Comma-separated types |
| Map URL | `hasMap` | No | Google Maps URL |
| Attractions | `includesAttraction` | No | Comma-separated attraction names |
| Booking URL | `tourBookingPage` | No | URL to book tours |

**JSON-LD output:**
```json
{
  "@type": "TouristDestination",
  "@id": "https://example.com/destination/crab-island/#touristdestination",
  "name": "Crab Island, Destin FL",
  "address": "Destin, FL 32541",
  "geo": { "@type": "GeoCoordinates", "latitude": 30.3935, "longitude": -86.5085 },
  "touristType": ["Water tourism", "Family tourism"],
  "includesAttraction": [
    { "@type": "TouristAttraction", "name": "Crab Island Sandbar" }
  ]
}
```

---

## Vehicle

**Schema.org:** `Thing > Product > Vehicle`

A boat, vessel, or vehicle used for tours/charters.

**When to use:** Charter boats, pontoons, parasail boats, tour buses, any vehicle that is part of a tourism operation.

**Fields:**

| Field | Schema Property | Required | Description |
|-------|----------------|----------|-------------|
| Year | `modelDate` | No | Year/model |
| Length | `vehicleConfiguration` supplement | No | Vessel length |
| Engine | `vehicleConfiguration` supplement | No | Engine details |
| Max Passengers | `seatingCapacity` | No | Maximum passengers |
| Vessel Type | `bodyType` | No | Type of vessel |
| Manufacturer | `manufacturer` | No | Manufacturer name |
| Amenities | `additionalProperty` | No | One amenity per line |

**JSON-LD output:**
```json
{
  "@type": ["Vehicle", "Product"],
  "@id": "https://example.com/vessel/barletta-cabrio/#vehicle",
  "name": "2022 Barletta Cabrio — 200HP",
  "modelDate": "2022",
  "bodyType": "Tritoon",
  "seatingCapacity": 13,
  "manufacturer": { "@type": "Organization", "name": "Barletta" },
  "vehicleConfiguration": "24 ft, 200HP Mercury Verado"
}
```

---

## Course

**Schema.org:** `Thing > Intangible > Course`

An educational course or certification program.

**When to use:** Training courses, certification programs, workshops, educational offerings.

**Fields:**

| Field | Schema Property | Required | Description |
|-------|----------------|----------|-------------|
| Credential Awarded | `educationalCredentialAwarded` | No | Certificate/credential on completion |
| Delivery Mode | `courseMode` | No | In-person, Online, or Hybrid |
| Duration | `timeToComplete` | No | ISO 8601 duration |
| Prerequisites | `coursePrerequisites` | No | Required prerequisites |
| Price | `offers` > Offer > price | No | Course price |
| Currency | `offers` > Offer > priceCurrency | No | ISO 4217 |
| Enrollment URL | `offers` > Offer > url | No | Link to enroll |
| Provider Name | `provider` > Organization > name | No | Course provider |
| Course Code | `courseCode` | No | Internal course code |

**JSON-LD output:**
```json
{
  "@type": "Course",
  "@id": "https://example.com/course/cpr-certification/#course",
  "name": "CPR Certification Course",
  "educationalCredentialAwarded": "American Heart Association CPR Certification",
  "courseMode": "In-person",
  "timeToComplete": "PT4H",
  "courseCode": "CPR-101",
  "provider": { "@type": "Organization", "name": "Express Training Services" },
  "offers": { "@type": "Offer", "price": "75", "priceCurrency": "USD" }
}
```

---

## Existing Types

### Event

**CPT:** `mm_event`

Events with specific dates, location, organizer, pricing. Supports attendance modes (in-person, online, mixed) and event types (music, sports, business, etc.).

**Fields:** Start/End Date, Location, Organizer, Price, Status, Attendance Mode, Event Type, Ticket URL.

### Service

**CPT:** `mm_service`

Services offered by a business. Supports service type, area served, pricing, booking URL, duration.

**Fields:** Service Type, Area Served, Price, Currency, Booking URL, Duration, What's Included, Provider Name.

### HowTo

**CPT:** `mm_how_to`

Step-by-step guides with supplies, tools, and steps.

**Fields:** Total Time, Estimated Cost, Supplies (dynamic list), Tools (dynamic list), Steps (dynamic with name, text, image).

### FAQPage

**CPT:** `mm_faq_page`

FAQ pages with question/answer pairs.

**Fields:** Dynamic Q&A pairs (up to 20).

### Product

**Override type** — can be assigned to any post type.

Products with brand, price, availability. WooCommerce compatible (auto-populates from WC product data when available).

**Fields:** Brand, Price, Currency, Availability.
