# Schema Expansion Roadmap

**Created:** 2026-09-13
**Status:** Planning — research complete, implementation not started
**Scope:** MetaManager plugin schema type expansion for tourism, charters, education, home services, and publication sites

---

## Table of Contents

1. [Research Findings](#1-research-findings)
2. [Design Principles](#2-design-principles)
3. [New Schema Types](#3-new-schema-types)
4. [Business Profile Additions](#4-business-profile-additions)
5. [Page Templates](#5-page-templates)
6. [ouachita.online Strategy](#6-ouachitaonline-strategy)
7. [Existing Module Integration](#7-existing-module-integration)
8. [Testing Strategy](#8-testing-strategy)
9. [Implementation Phases](#9-implementation-phases)
10. [File Changes](#10-file-changes)
11. [Documentation Plan](#11-documentation-plan)
12. [CPT Menu Grouping](#12-cpt-menu-grouping-research-findings)
13. [Tourism Business Model Mapping](#13-tourism-business-model-mapping-research-findings)
14. [Open Questions](#14-open-questions)
15. [Code Patterns to Follow](#15-code-patterns-to-follow)

---

## 1. Research Findings

### Client Portfolio Analysis (gapcreekmedia.com/client-list/)

20 actively managed sites. Industries identified:

| Industry | Clients | Count |
|---|---|---|
| Tourism / Boat Rental | Prime Time Charters (pontoon rental + captain) | 1 |
| Tourism / Fishing Charters | Charter Boat Special K, Sail Away Destin, No Alibi Charters, Smile N Wave | 4 |
| Tourism / Adventure Activities | Sun Dogs Parasail (parasailing, dolphin cruises) | 1 |
| Home Services / Construction | Delta Awnings, Suncrete, Texas Star Restoration, Gutter Tymer, ScenicScape, Rye-Land Lawn Care | 6 |
| Professional Services | The PEO Solution, Professional Moving Services | 2 |
| Education / Training | Express Training Services (medical certification courses) | 1 |
| Beauty / Personal Care | Destin Hair Studio | 1 |
| Food / Bakery | Cakemasters Bakery II | 1 |
| Event Venue | The Event Room | 1 |
| Real Estate | Surfside Property Group | 1 |
| Gardens / Landscaping | Sundrop Gardens | 1 |

### Current MetaManager Schema Coverage

**Schema types supported:** WebPage, AboutPage, ContactPage, ProfilePage, Calendar, FAQPage, BlogPosting, HowTo, Event, Product, Service

**CPTs registered:** `mm_event`, `mm_service`, `mm_how_to`, `mm_faq_page`

**Page templates:** AboutPage (`mm-about`), ContactPage (`mm-contact`), Calendar (`mm-calendar`)

**Business Profile types:** 45+ LocalBusiness subtypes across 8 groups (General, Professional Services, Medical & Wellness, Home & Construction, Food & Dining, Retail, Automotive, Beauty & Personal Care, Education & Fitness, Lodging & Travel)

### Identified Gaps

**Gap 1 — Tourism (6 clients, largest segment)**
- Prime Time Charters: Boat rental with captain-for-hire. Needs `BoatRental` business type, `Vehicle` CPT for pontoons, `TouristTrip` CPT for rental periods (4hr/6hr/8hr)
- Charter Boat Special K: Deep sea fishing charter. Needs `FishingCharter` business type, `TouristTrip` CPT for fishing trips, seasonal fish calendar (Event CPT)
- Sail Away Destin, No Alibi Charters, Smile N Wave: Sailing/charter trips. Need `BoatTour` business type, `TouristTrip` CPT
- Sun Dogs Parasail: Parasailing + dolphin cruises. Not a charter — it's an adventure activity launched from a boat. Needs `BoatTour` business type, `TouristTrip` CPT with `touristType` "Adventure tourism" / "Water sports"
- No `TouristTrip` type for bookable tours, rentals, or experiences
- No `TouristDestination` type for promoted places (Crab Island, Destin Harbor)
- No `Vehicle` type for vessel listings (pontoons, sportfish, parasail boat)
- No `TourismBusiness` subtypes in Business Profile (FishingCharter, BoatRental, BoatTour)

**Gap 2 — Education / Training (1 client)**
- Express Training Services: Medical certification courses (CPR, phlebotomy, etc.)
- Needs `Course` CPT with `educationalCredentialAwarded`, `timeToComplete`, `courseMode`
- Business Profile has `EducationalOrganization` — business type covered
- No `Course` type in MetaManager currently

**Gap 3 — Home Services (6 clients)**
- `Service` type works but lacks: service area specificity, license/insurance info, estimate request URL
- Business Profile already has `HomeAndConstructionBusiness` and subtypes — this gap is lower priority

**Gap 4 — Beauty / Personal Care (1 client)**
- Business Profile already has `BeautySalon`, `HairSalon` — covered
- Service type works for individual treatments

**Gap 5 — Real Estate (1 client)**
- Business Profile already has `RealEstateAgent` — covered
- Would need `RealEstateListing` for property listings — low priority (1 client)

**Gap 6 — Food / Bakery (1 client)**
- Business Profile already has `Bakery`, `FoodEstablishment` — covered

**Gap 7 — Event Venue (1 client)**
- Existing `Event` CPT works for events
- Would benefit from `Venue` page template for the venue itself — low priority

### Schema.org Usage Context

- Schema.org vocabulary: 823 types, 1529 properties (v30.0, 2026-03-19)
- `TouristTrip`: 10K–100K domains (growing, "new" area per schema.org)
- `TouristDestination`: 10K–100K domains (growing, "new" area per schema.org)
- `Vehicle`: 10K–100K domains
- `TourismBusiness`: subtype of `LocalBusiness`, used by tourism operators
- Google rich result support is a subset — AI search (ChatGPT, Perplexity, Bing Copilot), voice assistants, and other consumers use the broader schema.org vocabulary
- MetaManager should emit comprehensive, correct schema.org markup without limiting to Google's rich result subset

### Google Rich Result Types (for reference)

Google supports rich results for: Article, Breadcrumb, Carousel, Course list, Event, Local business, Product, Profile page, Q&A, Recipe, Review snippet, Vacation rental, Video.

TouristTrip and TouristDestination do NOT produce Google rich results currently, but:
- They are used by AI search systems for content understanding
- They strengthen the entity graph (connecting business → trips → destinations → attractions)
- They may gain rich result support as schema.org matures
- Review/AgregateRating on Trip pages CAN produce rich results

---

## 2. Design Principles

### CPT vs Template vs Post Type Override

**Use a CPT when:**
- The content type is created and managed by the business as discrete entries
- Each entry has its own structured data fields that need a dedicated metabox
- Multiple entries of the same type exist on a site (e.g., multiple trips, multiple boats)
- The content type has a meaningful archive page
- Examples: Event, Service, Trip, Vessel, Destination

**Use a Page Template when:**
- The page is a singleton — one per site
- The schema data is auto-generated from Business Profile settings or from other CPTs
- The page serves an aggregating role (Calendar pulls from Events)
- Examples: About page, Contact page, Calendar, Area Guide, Venue page

**Use Post Type Override (Schema admin tab) when:**
- The content maps to BlogPosting, WebPage, or other types that don't need custom fields
- The "Default Schema Type per Post Type" setting handles this
- Examples: blog posts → BlogPosting, generic pages → WebPage or FAQPage

### Schema Emission Pattern

MetaManager is self-contained. It provides its own templates for all CPTs via the `template_include` filter. Themes provide the outer shell (header, footer, CSS) via `get_header()`/`get_footer()`, but all content rendering and schema emission is plugin-controlled. We do not depend on themes having specific template files.

```
Every page emits:
  ├── WebSite node (always)
  ├── Organization/LocalBusiness node (always, from Business Profile)
  ├── SiteNavigationElement (if menus exist)
  ├── WebPage node (always, type varies by content)
  │   └── For CPTs: WebPage container + separate content node
  │   └── For templates: schema type applied to WebPage node itself
  │   └── For posts/pages: schema type from admin settings or metabox override
  ├── BreadcrumbList (if enabled)
  ├── Content-specific node (BlogPosting, Event, Service, TouristTrip, etc.)
  │   └── Merges per-post schema_fields from metabox
  │   └── build_node_additions() converts flat fields to nested JSON-LD
  ├── ItemList (on taxonomy archives)
  └── Custom JSON-LD (power user escape hatch)
```

### Template Rendering

MetaManager provides its own templates for all CPTs via the `template_include` filter. We do NOT depend on themes having `single-{post_type}.php` or `archive-{post_type}.php` files. The plugin's templates render the post content and emit the correct schema. Themes provide the outer shell (header, footer, CSS) but not the CPT-specific logic.

**Pattern:** The plugin checks if the current post is one of our CPTs. If so, it returns a plugin-side template path. The template calls `get_header()` and `get_footer()` (which come from the theme) but handles all content and schema rendering itself.

```php
add_filter( 'template_include', function( $template ) {
    if ( is_singular( 'mm_trip' ) ) {
        return MM_META_DIR . 'templates/single-mm-trip.php';
    }
    if ( is_post_type_archive( 'mm_trip' ) ) {
        return MM_META_DIR . 'templates/archive-mm_trip.php';
    }
    return $template;
} );
```

### Cross-Type Relationships

Types reference each other via `@id` links in the JSON-LD graph:
- **Trip → Destination**: `itinerary` property references TouristDestination
- **Trip → Vessel**: `vehicle` property (custom) or description supplement
- **Trip → Business**: `provider` references site's Organization node
- **Destination → Attraction**: `includesAttraction` references TouristAttraction nodes
- **Event → Venue**: `location` references Place node
- **Service → Business**: `provider` references site's Organization node
- **All content types → WebPage**: `isPartOf` references the WebPage node
- **All content types → Image**: `image` references ImageObject node

---

## 3. New Schema Types

### 3.1 TouristTrip

**Schema.org:** `Thing > Intangible > Trip > TouristTrip`
**CPT slug:** `mm_trip`
**Rewrite slug:** `trip`
**Icon:** `dashicons-location-alt`
**Supports:** title, editor, thumbnail, excerpt

**Description:** A bookable tour, charter, or experience. NOT an Event — a Trip is a standing offering available on any date. An Event happens on specific dates. A charter site would have Trips (the offerings) and Events (seasonal happenings like the Destin Fishing Rodeo).

**Client mapping:**
- Prime Time Charters: 4hr/6hr/8hr pontoon rental periods → Trips (with `BoatRental` business type, not `FishingCharter`)
- Charter Boat Special K: deep sea fishing charters → Trips
- Sun Dogs Parasail: parasailing adventures, dolphin cruises, sunset cruises → Trips (adventure activities, not charters)
- Sail Away Destin, No Alibi Charters, Smile N Wave: sailing/charter trips → Trips

**Metabox fields:**

| Field key | Schema property | Type | Required | Placeholder | Description |
|---|---|---|---|---|---|
| `trip_departure_name` | `tripOrigin` > Place > name | text | yes | e.g. Harborwalk Village, Destin | Boarding/departure location name |
| `trip_departure_address` | `tripOrigin` > Place > address | text | no | e.g. 10 Harbor Blvd, Destin FL 32541 | Departure address |
| `trip_departure_lat` | `tripOrigin` > Place > geo > latitude | text | no | 30.3935 | Departure latitude |
| `trip_departure_lng` | `tripOrigin` > Place > geo > longitude | text | no | -86.5085 | Departure longitude |
| `trip_destination_name` | `itinerary` > Place > name | text | no | e.g. Crab Island, Gulf of Mexico | Primary destination |
| `trip_duration` | (description supplement) | text | no | e.g. 4 hours, Half day | Human-readable duration |
| `trip_duration_iso` | `estimatedDuration` | text | no | e.g. PT4H, PT30M | ISO 8601 duration |
| `trip_max_passengers` | `maximumAttendeeCapacity` | number | no | 13 | Max passengers/guests |
| `trip_price` | `offers` > Offer > price | text | yes | e.g. 350 or 75-200 | Numeric or range |
| `trip_currency` | `offers` > Offer > priceCurrency | text | no | USD | ISO 4217 |
| `trip_booking_url` | `offers` > Offer > url | url | no | | Link to book this trip |
| `trip_departure_time` | `departureTime` | time | no | | Typical departure time |
| `trip_arrival_time` | `arrivalTime` | time | no | | Typical return time |
| `trip_tourist_type` | `touristType` | text | no | e.g. Family tourism, Adventure tourism | Comma-separated tourist audience types |
| `trip_includes` | (description supplement) | textarea | no | | What's included (cooler, lily pad, stereo, etc.) |
| `trip_vessel_id` | (cross-reference) | number | no | | Linked Vessel CPT post ID |

**JSON-LD output spec:**
```json
{
  "@type": "TouristTrip",
  "@id": "https://example.com/trip/crab-island-pontoon/#touristtrip",
  "name": "4 Hour Crab Island Pontoon Charter",
  "description": "...",
  "url": "https://example.com/trip/crab-island-pontoon/",
  "image": { "@id": "https://example.com/trip/crab-island-pontoon/#primaryimage" },
  "datePublished": "2026-01-15T10:00:00+00:00",
  "dateModified": "2026-09-10T14:30:00+00:00",
  "isPartOf": { "@id": "https://example.com/trip/crab-island-pontoon/#webpage" },
  "tripOrigin": {
    "@type": "Place",
    "name": "Harborwalk Village, Destin",
    "address": "10 Harbor Blvd, Destin FL 32541",
    "geo": { "@type": "GeoCoordinates", "latitude": 30.3935, "longitude": -86.5085 }
  },
  "itinerary": {
    "@type": "ItemList",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "item": { "@type": "TouristAttraction", "name": "Crab Island" } }
    ]
  },
  "estimatedDuration": "PT4H",
  "maximumAttendeeCapacity": 13,
  "touristType": ["Family tourism", "Water sports"],
  "departureTime": "09:00:00",
  "arrivalTime": "13:00:00",
  "offers": {
    "@type": "Offer",
    "price": "350",
    "priceCurrency": "USD",
    "url": "https://example.com/book/crab-island-pontoon/",
    "availability": "https://schema.org/InStock"
  },
  "provider": { "@id": "https://example.com/#organization" }
}
```

### 3.2 TouristDestination

**Schema.org:** `Thing > Place > TouristDestination`
**CPT slug:** `mm_destination`
**Rewrite slug:** `destination`
**Icon:** `dashicons-location`
**Supports:** title, editor, thumbnail, excerpt

**Description:** A place or area being promoted. Contains or is colocated with TouristAttractions. For charter sites: Crab Island, Destin Harbor, Choctawhatchee Bay. For ouachita.online: Ouachita Parish, Monroe, West Monroe, Kiroli Park, Black Bayou.

**Metabox fields:**

| Field key | Schema property | Type | Required | Placeholder | Description |
|---|---|---|---|---|---|
| `destination_address` | `address` | text | no | e.g. Destin, FL 32541 | Location address |
| `destination_lat` | `geo` > GeoCoordinates > latitude | text | no | 30.3935 | Latitude |
| `destination_lng` | `geo` > GeoCoordinates > longitude | text | no | -86.5085 | Longitude |
| `destination_tourist_type` | `touristType` | text | no | e.g. Water tourism, Cultural tourism | Comma-separated types |
| `destination_map_url` | `hasMap` | url | no | | Google Maps or similar URL |
| `destination_attractions` | `includesAttraction` (comma-separated) | text | no | e.g. Crab Island Sandbar, Destin Harbor | Attraction names (auto-creates TouristAttraction nodes) |
| `destination_booking_url` | `tourBookingPage` | url | no | | URL to book tours of this destination |

**JSON-LD output spec:**
```json
{
  "@type": "TouristDestination",
  "@id": "https://example.com/destination/crab-island/#touristdestination",
  "name": "Crab Island, Destin FL",
  "description": "...",
  "url": "https://example.com/destination/crab-island/",
  "image": { "@id": "https://example.com/destination/crab-island/#primaryimage" },
  "address": "Destin, FL 32541",
  "geo": { "@type": "GeoCoordinates", "latitude": 30.3935, "longitude": -86.5085 },
  "touristType": ["Water tourism", "Family tourism"],
  "hasMap": "https://maps.google.com/?q=Crab+Island+Destin+FL",
  "tourBookingPage": "https://example.com/book/",
  "includesAttraction": [
    { "@type": "TouristAttraction", "name": "Crab Island Sandbar" },
    { "@type": "TouristAttraction", "name": "Destin Harbor" }
  ]
}
```

### 3.3 Vehicle

**Schema.org:** `Thing > Product > Vehicle`
**CPT slug:** `mm_vessel`
**Rewrite slug:** `vessel`
**Icon:** `dashicons-car`
**Supports:** title, editor, thumbnail, excerpt

**Description:** A boat, vessel, or vehicle used for tours/charters. Not all trips need a vessel listing, but charter sites with multiple boats benefit from dedicated vessel pages with specs.

**Client mapping:**
- Prime Time Charters: 3 Barletta pontoons (different HP/capacity)
- Charter Boat Special K: 40ft Bertram Widebody
- Sun Dogs Parasail: 31ft Ocean Pro

**Metabox fields:**

| Field key | Schema property | Type | Required | Placeholder | Description |
|---|---|---|---|---|---|
| `vessel_year` | `modelDate` | text | no | 2022 | Year/model |
| `vessel_length` | `vehicleConfiguration` supplement | text | no | 24 ft | Vessel length |
| `vessel_engine` | `vehicleEngine` supplement | text | no | 200HP Mercury Verado | Engine details |
| `vessel_passengers` | `seatingCapacity` | number | no | 13 | Max passengers |
| `vessel_type` | `bodyType` | text | no | e.g. Tritoon, Sportfish, Parasail boat | Vessel type |
| `vessel_manufacturer` | `manufacturer` | text | no | e.g. Barletta, Bertram | Manufacturer |
| `vessel_amenities` | `additionalProperty` | textarea | no | e.g. Cooler with ice, Bluetooth stereo, Lily pad | One amenity per line |
| `vessel_horsepower` | `additionalProperty` | text | no | 200 | Engine HP |
| `vessel_speed` | `speed` supplement | text | no | e.g. 30 knots | Top speed |

**JSON-LD output spec:**
```json
{
  "@type": ["Vehicle", "Product"],
  "@id": "https://example.com/vessel/barletta-cabrio-200hp/#vehicle",
  "name": "2022 Barletta Cabrio — 200HP",
  "description": "...",
  "url": "https://example.com/vessel/barletta-cabrio-200hp/",
  "image": { "@id": "https://example.com/vessel/barletta-cabrio-200hp/#primaryimage" },
  "modelDate": "2022",
  "seatingCapacity": 13,
  "bodyType": "Tritoon",
  "manufacturer": { "@type": "Organization", "name": "Barletta" },
  "vehicleConfiguration": "24ft Tritoon, 200HP Mercury Verado",
  "speed": { "@type": "QuantitativeValue", "maxValue": 30, "unitText": "knots" },
  "additionalProperty": [
    { "@type": "PropertyValue", "name": "Length", "value": "24 ft" },
    { "@type": "PropertyValue", "name": "Horsepower", "value": "200 HP" },
    { "@type": "PropertyValue", "name": "Engine", "value": "Mercury Verado" },
    { "@type": "PropertyValue", "name": "Amenities", "value": "Cooler with ice, Floating lily pad, JBL Bluetooth stereo, On-board inflators, BOTE couch" }
  ]
}
```

### 3.4 Course

**Schema.org:** `Thing > Intangible > Course`
**CPT slug:** `mm_course`
**Rewrite slug:** `course`
**Icon:** `dashicons-welcome-learn-more`
**Supports:** title, editor, thumbnail, excerpt

**Description:** An educational course or certification program. Used for training providers that offer structured courses with credentials.

**Client mapping:**
- Express Training Services: Medical certification courses (CPR, Phlebotomy, EKG, etc.)

**Metabox fields:**

| Field key | Schema property | Type | Required | Placeholder | Description |
|---|---|---|---|---|---|
| `course_credential` | `educationalCredentialAwarded` | text | no | e.g. CPR Certification, Phlebotomy Technician | Credential/certificate awarded |
| `course_mode` | `courseMode` | select | no | In-person, Online, Hybrid | Delivery mode |
| `course_duration` | `timeToComplete` | text | no | e.g. PT8H, P2D | ISO 8601 duration |
| `course_prerequisites` | `coursePrerequisites` | text | no | e.g. High school diploma | Prerequisites |
| `course_price` | `offers` > Offer > price | text | no | e.g. 150 | Course price |
| `course_currency` | `offers` > Offer > priceCurrency | text | no | USD | ISO 4217 |
| `course_enrollment_url` | `offers` > Offer > url | url | no | | Link to enroll |
| `course_provider` | `provider` > Organization > name | text | no | | Course provider name |
| `course_code` | `courseCode` | text | no | e.g. CPR-101 | Course code/ID |

**JSON-LD output spec:**
```json
{
  "@type": "Course",
  "@id": "https://example.com/course/cpr-certification/#course",
  "name": "CPR Certification Course",
  "description": "...",
  "url": "https://example.com/course/cpr-certification/",
  "image": { "@id": "https://example.com/course/cpr-certification/#primaryimage" },
  "courseCode": "CPR-101",
  "educationalCredentialAwarded": "American Heart Association CPR Certification",
  "courseMode": "In-person",
  "timeToComplete": "PT4H",
  "coursePrerequisites": "None",
  "provider": { "@type": "Organization", "name": "Express Training Services" },
  "offers": {
    "@type": "Offer",
    "price": "75",
    "priceCurrency": "USD",
    "url": "https://example.com/enroll/cpr-certification/"
  }
}
```

### 3.5 Schema Type List Additions

Add to `MM_Schema_Types::get_schema_types()`:

```php
// ── Tourism ─────────────────────────────────────────────────────
'TouristTrip'        => 'TouristTrip — Bookable tour/experience',
'TouristDestination' => 'TouristDestination — Place/area being promoted',
// ── Vehicles ────────────────────────────────────────────────────
'Vehicle'            => 'Vehicle — Boat, car, or other vehicle',
// ── Education ───────────────────────────────────────────────────
'Course'             => 'Course — Educational course or certification',
```

### 3.5 WebPage Node Type List Update

In `add_webpage_node()`, add new content types to the "content types" list:

```php
if ( in_array( $schema_type, [
    'BlogPosting', 'Article', 'HowTo', 'Product', 'Event', 'Service',
    'TouristTrip', 'Vehicle'
], true ) ) {
    $type = 'WebPage';
}
```

Same update needed in `add_content_node()` for the skip list:
```php
if ( in_array( $type, [
    'WebPage', 'WebSite', 'AboutPage', 'ContactPage', 'Calendar',
    'TouristDestination'
], true ) ) {
    return; // Already on the WebPage node or handled elsewhere
}
```

---

## 4. Business Profile Additions

### TourismBusiness Subtypes

Add to `MM_Mod_Local::get_business_types()`:

```php
'Tourism & Recreation' => [
    'TourismBusiness'        => 'Tourism Business (generic)',
    'BoatRental'             => 'Boat Rental',
    'BoatTour'               => 'Boat Tour',
    'FishingCharter'         => 'Fishing Charter',
    'AmusementPark'          => 'Amusement Park',
    'Museum'                 => 'Museum',
    'SportsActivityLocation' => 'Sports / Recreation',
],
```

**Client mapping:**
- Prime Time Charters → `BoatRental` (pontoon rental with captain-for-hire, not a charter)
- Charter Boat Special K → `FishingCharter` (deep sea fishing)
- Sun Dogs Parasail → `BoatTour` (parasailing + dolphin cruises — adventure activities, not charters)
- Sail Away Destin → `BoatTour` (sailing tours)
- No Alibi Charters → `FishingCharter` or `BoatTour`
- Smile N Wave → `BoatTour` (sailing adventures)
- The Event Room → existing `SportsActivityLocation` or generic `LocalBusiness`
- Destin Hair Studio → existing `HairSalon`

### HomeServices Subtypes (already covered)

The existing Home & Construction group already has:
- `HomeAndConstructionBusiness` (generic)
- `Electrician`, `GeneralContractor`, `HousePainter`, `HVACBusiness`, `Locksmith`
- `MovingCompany`, `Plumber`, `RoofingContractor`, `LandscapingBusiness`, `HouseCleaning`

Client mapping:
- Delta Awnings → `GeneralContractor`
- Suncrete → `GeneralContractor`
- Texas Star Restoration → `GeneralContractor`
- Gutter Tymer → `RoofingContractor` or `GeneralContractor`
- ScenicScape → `LandscapingBusiness`
- Rye-Land Lawn Care → `LandscapingBusiness`
- Professional Moving Services → `MovingCompany`
- Sundrop Gardens → `LandscapingBusiness` or `Store` (retail garden center)
- The PEO Solution → existing `EmploymentAgency` (PEO = Professional Employer Organization)
- Express Training Services → `EducationalOrganization` (medical certification training)

---

## 5. Page Templates

### 5.1 Area Guide (`mm-area-guide`)

**Purpose:** Tourism publication landing page. Aggregates destinations, attractions, events, and local businesses for a geographic area.

**Schema type:** `TouristDestination`

**Use case:** ouachita.online would use this for the main "Ouachita Parish" guide page. Charter sites could use it for "Destin" or "Crab Island" area guides.

**Behavior:**
- Renders page content as normal
- Emits `TouristDestination` JSON-LD on the WebPage node
- Pulls from `mm_destination` CPT for `includesAttraction` nodes
- Pulls from `mm_event` CPT for upcoming events in the area
- Pulls from Business Profile for local business context

**Template file:** `templates/page-mm-area-guide.php`

### 5.2 Venue (`mm-venue`)

**Purpose:** Event venue page. Complements the existing Event CPT.

**Schema type:** `Place` (with `maximumAttendeeCapacity`, `amenityFeature`)

**Use case:** The Event Room (fwbeventroom.com) would use this for their venue page. Events would reference the venue in their `location` property.

**Behavior:**
- Renders page content as normal
- Emits `Place` JSON-LD with capacity, amenities, address
- Events that reference this venue get linked via `location` property

**Template file:** `templates/page-mm-venue.php`

### 5.3 Template Registration

Add to `MM_Schema_Post_Types::TEMPLATES`:

```php
public const TEMPLATES = [
    'mm-about'       => 'AboutPage',
    'mm-contact'     => 'ContactPage',
    'mm-calendar'    => 'Calendar',
    'mm-area-guide'  => 'TouristDestination',
    'mm-venue'       => 'Place',
];
```

---

## 6. ouachita.online Strategy

### Site Profile

- **Domain:** ouachita.online
- **Purpose:** Tourism and lifestyle publication for Ouachita Parish, Louisiana
- **Content:** Area guides, attraction listings, event coverage, local business directory, blog articles
- **Business Profile type:** `Organization` or `LocalBusiness` (generic) — it's a publication, not a single business

### Content Type Mapping

| Content | Schema Type | Implementation |
|---|---|---|
| Area guides (Ouachita Parish, Monroe, West Monroe) | TouristDestination | Page template `mm-area-guide` |
| Attraction pages (Kiroli Park, Black Bayou, etc.) | TouristAttraction | `mm_destination` CPT |
| Event listings (festivals, markets, concerts) | Event | `mm_event` CPT (already exists) |
| Local business directory | LocalBusiness subtypes | Page template or custom queries |
| Restaurant/bakery pages | Restaurant/Bakery | `mm_destination` CPT with FoodEstablishment type |
| Blog articles about the area | BlogPosting/Article | Default `post` type (already works) |
| Things to do guides | TouristTrip | `mm_trip` CPT (for bookable experiences) |
| Training/certification courses | Course | `mm_course` CPT (if training providers join) |

### Recommended Plugin Configuration

1. **Business Profile:** Type = `Organization`, Name = "Ouachita Online"
2. **Schema admin:** Set `post` default to `BlogPosting`, `page` default to `WebPage`
3. **Create pages:** Home, About, Contact, Area Guide (using `mm-area-guide` template)
4. **Create Destinations:** Ouachita Parish, Monroe, West Monroe, Kiroli Park, Black Bayou Lake, etc.
5. **Create Events:** Local festivals, markets, seasonal events
6. **Blog:** Articles about local attractions, things to do, seasonal guides

### Structured Data Opportunities

- **BlogPosting** on articles about attractions → strengthens topical authority
- **TouristDestination** on area guides → geographic entity signals
- **Event** on local happenings → rich results in Google Search
- **TouristAttraction** on individual attractions → knowledge graph signals
- **LocalBusiness** directory → local SEO signals for the publication
- **Review** on attractions/restaurants → review snippets in search
- **ItemList** on directory pages → carousel eligibility
- **Course** on training/certification listings → course rich results

---

## 7. Existing Module Integration

### Sitemap Module (`MM_Mod_Sitemap`)

New CPTs need to be included in the XML sitemap. The sitemap module reads from `sitemap.post_types` setting.

**Impact:** New CPTs automatically appear in the sitemap if the user enables them in the Sitemap settings tab. No code changes needed — the settings UI already iterates over all public post types.

**Action:** Verify that `mm_trip`, `mm_destination`, `mm_vessel`, `mm_course` appear in the Sitemap settings tab after registration.

### RSS/Feed Module (`MM_Mod_RSS`)

New CPTs should be included in the RSS feed if the site owner wants them.

**Impact:** WordPress does NOT include CPTs in the main RSS feed by default. The `pre_get_posts` hook is needed.

**Action:** Provide a setting in MetaManager to include specific CPTs in the main feed. Or document that site owners should use `pre_get_posts` to add CPTs to the feed.

### Search Integration

New CPTs should be included in WordPress search results.

**Impact:** `register_post_type()` with `public => true` sets `exclude_from_search => false` by default. CPTs will appear in search results automatically.

**Action:** Verify behavior. If a site owner wants to exclude a CPT from search, they can set `exclude_from_search => true` via filter.

### Content Node Dispatch (`add_content_node()`)

The `add_content_node()` method in `MM_Mod_Schema` dispatches based on schema type. New types need handling.

**Current dispatch:**
- BlogPosting, Article → author/publisher nodes
- Event, Service, Product, HowTo, FAQPage → `build_node_additions()` merge
- WebPage, AboutPage, ContactPage, Calendar → skip (handled by WebPage node)

**New dispatch needed:**
- TouristTrip → `build_node_additions()` merge (tripOrigin, itinerary, offers, etc.)
- Vehicle → `build_node_additions()` merge (seatingCapacity, bodyType, etc.)
- Course → `build_node_additions()` merge (educationalCredentialAwarded, courseMode, etc.)
- TouristDestination → skip (handled by WebPage node, like ContactPage)

### SEO Plugin Compatibility

**MetaManager is NOT compatible with other SEO or media management software.** This includes Yoast SEO, Rank Math, All in One SEO, and any other plugin that emits schema, meta tags, or OG data. MetaManager is the sole provider of structured data and metadata for sites it manages.

**Action:** No compatibility layer needed. Document this clearly.

---

## 8. Testing Strategy

### Unit Tests

| Test | File | Coverage |
|---|---|---|
| `test_get_schema_types_has_new_types` | `Test_MM_Schema_Types_Unit.php` | Verify TouristTrip, TouristDestination, Vehicle, Course appear in type list |
| `test_get_fields_by_type_tourist_trip` | `Test_MM_Schema_Types_Unit.php` | Verify all Trip field definitions exist with correct types |
| `test_get_fields_by_type_course` | `Test_MM_Schema_Types_Unit.php` | Verify all Course field definitions exist |
| `test_build_node_additions_tourist_trip` | `Test_MM_Schema_Types_Unit.php` | Verify JSON-LD output for Trip fields |
| `test_build_node_additions_course` | `Test_MM_Schema_Types_Unit.php` | Verify JSON-LD output for Course fields |
| `test_build_node_additions_vehicle` | `Test_MM_Schema_Types_Unit.php` | Verify JSON-LD output for Vehicle fields |
| `test_build_node_additions_tourist_destination` | `Test_MM_Schema_Types_Unit.php` | Verify JSON-LD output for Destination fields |

### Integration Tests

| Test | File | Coverage |
|---|---|---|
| `test_trip_schema_output` | `Test_MM_Modules.php` | Full JSON-LD graph for a Trip post |
| `test_destination_schema_output` | `Test_MM_Modules.php` | Full JSON-LD graph for a Destination post |
| `test_vessel_schema_output` | `Test_MM_Modules.php` | Full JSON-LD graph for a Vessel post |
| `test_course_schema_output` | `Test_MM_Modules.php` | Full JSON-LD graph for a Course post |
| `test_trip_webpage_node_type` | `Test_MM_Modules.php` | Verify Trip gets WebPage container, not TouristTrip |
| `test_destination_webpage_node_type` | `Test_MM_Modules.php` | Verify Destination gets TouristDestination on WebPage node |
| `test_tourism_business_types` | `Test_MM_Mod_Local_Unit.php` | Verify TourismBusiness subtypes appear in business types |
| `test_trip_vessel_relationship` | `Test_MM_Post_Meta_Panel.php` | Verify vessel post ID saves/loads correctly |
| `test_trip_sitemap_inclusion` | `Test_MM_Sitemap_Integration.php` | Verify Trip appears in sitemap when enabled |

### Lint and Static Analysis

- PHP lint on all modified files
- PHPStan on all modified files
- ShellCheck on any modified shell scripts

---

## 9. Implementation Phases

### Phase 1 — Core Tourism CPTs (highest client impact)

**Goal:** Register new CPTs, add schema types, add field definitions, emit JSON-LD.

1. Register `mm_trip`, `mm_destination`, `mm_vessel`, `mm_course` CPTs in `MM_Schema_Post_Types::TYPES`
2. Add `TouristTrip`, `TouristDestination`, `Vehicle`, `Course` to `MM_Schema_Types::get_schema_types()`
3. Add TouristTrip and Course field definitions to `MM_Schema_Types::get_fields_by_type()`
4. Add `build_node_additions()` handling for TouristTrip, TouristDestination, Vehicle, Course
5. Add Tourism & Recreation group to `MM_Mod_Local::get_business_types()`
6. Update `add_webpage_node()` type list (add TouristTrip, Vehicle to content types)
7. Update `add_content_node()` skip list (add TouristDestination)
8. Unit tests for new types, field definitions, JSON-LD output

### Phase 2 — Admin UI and Metaboxes

**Goal:** Build the metabox rendering for each new CPT.

1. Trip metabox: departure, destination, pricing, capacity, duration, booking URL, vessel reference
2. Destination metabox: address, geo, tourist type, attractions, map URL
3. Vessel metabox: year, length, engine, passengers, type, manufacturer, amenities
4. Course metabox: credential, mode, duration, prerequisites, price, enrollment URL, provider
5. WooCommerce product linking on Trip metabox (for bookable trips with WC products)
5. Business profile auto-population on Trip metabox (departure location, organizer)
6. Breadcrumb label field on all new CPTs
7. Integration tests for metabox rendering and save

### Phase 3 — Templates (Single, Archive, Page)

**Goal:** Provide all templates via plugin. No theme dependency.

1. Create `templates/single-mm_trip.php` — single trip page with TouristTrip schema
2. Create `templates/archive-mm_trip.php` — trips listing with ItemList schema
3. Create `templates/single-mm_destination.php` — single destination page with TouristDestination schema
4. Create `templates/archive-mm_destination.php` — destinations listing
5. Create `templates/single-mm_vessel.php` — single vessel page with Vehicle schema
6. Create `templates/archive-mm_vessel.php` — vessels listing
7. Create `templates/single-mm_course.php` — single course page with Course schema
8. Create `templates/archive-mm_course.php` — courses listing
9. Create `templates/page-mm-area-guide.php` — area guide page template (TouristDestination)
10. Create `templates/page-mm-venue.php` — venue page template (Place)
11. Register `template_include` filter for all CPT templates
12. Register page templates in `MM_Schema_Post_Types::TEMPLATES`

### Phase 4 — Cross-Linking and Relationships

**Goal:** Connect the types into a rich entity graph.

1. Trip → Vessel relationship (vessel selector metabox on Trip)
2. Trip → Destination relationship (destination selector metabox on Trip)
3. Destination → Attraction relationship (included attractions on Destination)
4. Event → Venue relationship (venue selector metabox on Event)
5. JSON-LD `@id` cross-references between related nodes
6. AggregateRating support on Trip and Destination
7. Review schema integration on Trip pages

### Phase 5 — Documentation

**Goal:** Comprehensive documentation for all new types and usage patterns.

1. Schema type reference (when to use each type, field definitions, JSON-LD output)
2. Industry guides (charter boats, home services, tourism publications)
3. CPT vs template decision guide
4. ouachita.online setup guide as reference implementation
5. Cross-linking guide (how types reference each other)
6. Update existing ROADMAP.md with new types

### Phase 6 — Advanced Features (future)

1. Seasonal availability on Trip (recurring schedule pattern)
2. Multi-day Trip support (subTrip/partOfTrip)
3. Trip reviews with structured data
4. Destination gallery with ImageObject schema
5. LocalBusiness directory shortcode with ItemList schema
6. Schema import/export for migrating between sites

---

## 10. File Changes

| File | Change | Phase |
|---|---|---|
| `includes/metadata/class-mm-schema-post-types.php` | Add `mm_trip`, `mm_destination`, `mm_vessel`, `mm_course` to TYPES. Add metabox rendering for each. Add templates to TEMPLATES. | 1, 2, 3 |
| `includes/metadata/class-mm-schema-types.php` | Add TouristTrip/TouristDestination/Vehicle/Course to `get_schema_types()`. Add field definitions to `get_fields_by_type()`. Add `build_node_additions()` handling. | 1 |
| `includes/metadata/modules/class-mm-mod-schema.php` | Add TouristTrip/Vehicle to content types list in `add_webpage_node()`. Add TouristDestination to skip list in `add_content_node()`. | 1 |
| `includes/metadata/modules/class-mm-mod-local.php` | Add Tourism & Recreation group to `get_business_types()`. | 1 |
| `templates/page-mm-area-guide.php` | New template for tourism area guides. | 3 |
| `templates/page-mm-venue.php` | New template for event venues. | 3 |
| `templates/single-mm_trip.php` | Single trip template with TouristTrip schema. | 3 |
| `templates/archive-mm_trip.php` | Trips archive template with ItemList schema. | 3 |
| `templates/single-mm_destination.php` | Single destination template with TouristDestination schema. | 3 |
| `templates/archive-mm_destination.php` | Destinations archive template. | 3 |
| `templates/single-mm_vessel.php` | Single vessel template with Vehicle schema. | 3 |
| `templates/archive-mm_vessel.php` | Vessels archive template. | 3 |
| `templates/single-mm_course.php` | Single course template with Course schema. | 3 |
| `templates/archive-mm_course.php` | Courses archive template. | 3 |
| `includes/metadata/admin/class-mm-metadata-help.php` | Add new types to help text and token documentation. | 5 |
| `tests/Unit/Test_MM_Schema_Types_Unit.php` | Add tests for new types in `get_schema_types()`, field definitions, and `build_node_additions()`. | 1 |
| `tests/Integration/Test_MM_Modules.php` | Add tests for TouristTrip/TouristDestination/Vehicle/Course JSON-LD output. | 1 |
| `tests/Integration/Test_MM_Post_Meta_Panel.php` | Add tests for new CPT metabox save/load. | 2 |
| `tests/Integration/Test_MM_Sitemap_Integration.php` | Add tests for new CPT sitemap inclusion. | 1 |
| `tests/Unit/Test_MM_Mod_Local_Unit.php` | Add tests for TourismBusiness subtypes. | 1 |

---

## 11. Documentation Plan

### In-Plugin Documentation

1. **Schema Type Reference** — `docs/schema-types.md`
   - Complete list of supported schema types
   - When to use each type
   - Field definitions and JSON-LD output examples
   - CPT vs template decision guide

2. **Industry Guides** — `docs/industries/`
   - `charter-boats.md` — TouristTrip, Vehicle, TourismBusiness setup
   - `home-services.md` — Service, LocalBusiness setup
   - `tourism-publications.md` — TouristDestination, Event, Article setup
   - `restaurants-food.md` — FoodEstablishment, Menu setup

3. **ouachita.online Reference** — `docs/reference-ouachita.md`
   - Complete setup walkthrough
   - Content type configuration
   - Schema output verification

### Admin UI Documentation

1. Help tab on each new CPT edit screen
2. Description text on each metabox field
3. "Use business profile value" links where applicable
4. Schema preview (future: show expected JSON-LD output)

---

## 12. CPT Menu Grouping (Research Findings)

### WordPress Pattern

WordPress supports grouping related CPTs under a single admin menu via the `show_in_menu` parameter in `register_post_type()`. The pattern:

1. Create a parent menu page (using `add_menu_page()` or a primary CPT)
2. Set each related CPT's `show_in_menu` to the parent menu slug

**Example from The Events Calendar plugin:**
```php
// Parent: "Events" top-level menu
add_menu_page('Events', 'Events', 'edit_posts', 'tribe_events', ...);

// Child CPTs: set show_in_menu to parent slug
register_post_type('tribe_venue', ['show_in_menu' => 'tribe_events', ...]);
register_post_type('tribe_organizer', ['show_in_menu' => 'tribe_events', ...]);
```

**Example from WooCommerce:**
```php
// Products, Orders, Coupons all under "WooCommerce" menu
register_post_type('product', ['show_in_menu' => 'woocommerce', ...]);
register_post_type('shop_order', ['show_in_menu' => 'woocommerce', ...]);
```

### MetaManager CPT Grouping Plan

**Current CPTs:** `mm_event`, `mm_service`, `mm_how_to`, `mm_faq_page` — each is a top-level menu item.

**Proposed grouping:**

| Menu Group | CPTs | Rationale |
|---|---|---|
| Events (existing top-level) | `mm_event` | Already exists |
| Services (existing top-level) | `mm_service` | Already exists |
| Content (new group) | `mm_how_to`, `mm_faq_page`, `mm_course` | Educational/reference content |
| Tourism (new group) | `mm_trip`, `mm_vessel`, `mm_destination` | Tourism-related content |

**Implementation approach:**
- Use `add_submenu_page()` to create group parent pages
- Set `show_in_menu` on grouped CPTs to the parent page slug
- The parent page shows a listing or redirect to the first child CPT
- `mm_event` and `mm_service` stay as top-level (they're the most used types)

**Alternative approach (simpler):**
- Don't group at all — keep all CPTs as top-level menu items
- The `mm_` prefix already namespaces them
- Simpler implementation, less UI complexity
- Users can use WordPress menu customization to reorder

**Recommendation:** Keep `mm_event` and `mm_service` as top-level. Group `mm_how_to`, `mm_faq_page`, `mm_course` under a "Content" submenu. Group `mm_trip`, `mm_vessel`, `mm_destination` under a "Tourism" submenu. Use `show_in_menu` pointing to a submenu page created with `add_submenu_page()`.

### Archive Templates

MetaManager provides plugin-side archive templates for all CPTs. No theme dependency.

**Implementation:** `template_include` filter returns plugin template paths for `is_post_type_archive()` and `is_singular()` checks on our CPTs. Templates call `get_header()`/`get_footer()` from the theme for the outer shell, but all content and schema rendering is plugin-controlled.

**Templates to provide:**
- `templates/single-mm_trip.php` — single trip page
- `templates/archive-mm_trip.php` — trips listing
- `templates/single-mm_destination.php` — single destination page
- `templates/archive-mm_destination.php` — destinations listing
- `templates/single-mm_vessel.php` — single vessel page
- `templates/archive-mm_vessel.php` — vessels listing
- `templates/single-mm_course.php` — single course page
- `templates/archive-mm_course.php` — courses listing

---

## 13. Tourism Business Model Mapping (Research Findings)

### Schema.org Tourism Type Hierarchy

```
Thing > Organization > LocalBusiness > TourismBusiness
  ├── BoatRental          — renting boats (vessel is the product)
  ├── BoatTour            — guided boat tours (experience is the product)
  ├── FishingCharter      — fishing trips (catch is the product)
  ├── AmusementPark       — amusement/theme parks
  └── TravelAgency        — travel booking services

Thing > Intangible > Trip > TouristTrip
  — a created itinerary visiting TouristAttractions/TouristDestinations
  — has: tripOrigin, itinerary, offers, provider, touristType, estimatedDuration
  — NOT an Event (no specific date; a standing offering)

Thing > Place > TouristDestination
  — a place containing TouristAttractions
  — has: includesAttraction, touristType, geo, address, tourBookingPage

Thing > Product > Vehicle
  — a device for transporting people/cargo
  — has: seatingCapacity, bodyType, vehicleConfiguration, manufacturer
```

### Client Business Model Mapping

| Client | Business Type | Schema Type | Trip touristType | Vessel? | Key Schema Properties |
|---|---|---|---|---|---|
| Prime Time Charters | `BoatRental` | TouristTrip | "Boat rental" | Yes (3 pontoons) | `tripOrigin`, `estimatedDuration`, `maximumAttendeeCapacity`, `offers` (price per period) |
| Charter Boat Special K | `FishingCharter` | TouristTrip | "Fishing charter" | Yes (1 sportfish) | `tripOrigin`, `estimatedDuration`, `offers`, fish calendar → Events |
| Sun Dogs Parasail | `BoatTour` | TouristTrip | "Adventure activity" | Yes (1 parasail boat) | `tripOrigin`, `estimatedDuration`, `offers`, `touristType` "Adventure tourism" |
| Sail Away Destin | `BoatTour` | TouristTrip | "Sailing tour" | Possibly | `tripOrigin`, `estimatedDuration`, `offers` |
| No Alibi Charters | `FishingCharter` | TouristTrip | "Fishing charter" | Possibly | `tripOrigin`, `offers` |
| Smile N Wave | `BoatTour` | TouristTrip | "Sailing tour" | Possibly | `tripOrigin`, `offers` |

### Key Insight: TouristTrip Works for All Models

All tourism businesses use `TouristTrip` as the content type. The differentiation comes from:

1. **Business Profile type** (`BoatRental`, `FishingCharter`, `BoatTour`) — sets the site-wide context
2. **`touristType` field** on each Trip — distinguishes "Boat rental" from "Fishing charter" from "Adventure activity"
3. **Vessel reference** — optional; not all businesses need a vessel listing
4. **`offers` structure** — price per period (rental), price per trip (charter), price per person (tour)

This keeps the schema clean and consistent while allowing each business to express its unique model.

### Schema Emission for Each Model

**BoatRental (Prime Time):**
```json
{
  "@type": "TouristTrip",
  "name": "4 Hour Pontoon Rental",
  "touristType": "Boat rental",
  "tripOrigin": { "@type": "Place", "name": "Harborwalk Village, Destin" },
  "estimatedDuration": "PT4H",
  "maximumAttendeeCapacity": 13,
  "offers": { "@type": "Offer", "price": "350", "priceCurrency": "USD" },
  "provider": { "@id": "site#organization" }
}
```

**FishingCharter (Special K):**
```json
{
  "@type": "TouristTrip",
  "name": "Deep Sea Fishing Charter",
  "touristType": "Fishing charter",
  "tripOrigin": { "@type": "Place", "name": "Harborwalk Village, Destin" },
  "estimatedDuration": "PT8H",
  "offers": { "@type": "Offer", "price": "1200", "priceCurrency": "USD" },
  "provider": { "@id": "site#organization" }
}
```

**BoatTour/Adventure (Sun Dogs):**
```json
{
  "@type": "TouristTrip",
  "name": "Parasailing Adventure",
  "touristType": ["Adventure activity", "Water sports"],
  "tripOrigin": { "@type": "Place", "name": "AJ's Seafood & Oyster Bar, Destin Harbor" },
  "estimatedDuration": "PT1H",
  "offers": { "@type": "Offer", "price": "99", "priceCurrency": "USD", "priceCurrency": "USD" },
  "provider": { "@id": "site#organization" }
}
```

---

## 14. Open Questions

| # | Question | Context | Status |
|---|---|---|---|
| Q1 | How should related CPTs be grouped in the admin menu? | WordPress supports grouping via `show_in_menu` parameter. Pattern: create a parent menu page, then set each related CPT's `show_in_menu` to that parent's slug. | **Resolved** — see CPT Menu Grouping section below |
| Q2 | Should Vessel be a CPT or a taxonomy on Trip? | CPT is more flexible (vessel has its own page/structured data). | **Resolved** — CPT |
| Q3 | Should Destination support hierarchical relationships (parent/child)? | e.g., "Destin" parent, "Crab Island" child. WordPress supports hierarchical CPTs. | **Decision needed** |
| Q4 | Should we add `TouristAttraction` as a separate CPT or use Destination with a different schema type? | TouristAttraction is a Place type. | **Decision needed** — recommend using Destination with per-post schema type override to TouristAttraction |
| Q5 | How should Trip reference Vessel? | Custom meta box with search/select, matching WooCommerce product linking pattern. | **Resolved** — meta box selector |
| Q6 | Should we support multi-day Trips (subTrip/partOfTrip)? | schema.org TouristTrip supports subTrip for multi-day itineraries. | **Defer to Phase 6** |
| Q7 | ~~Should we add `Course` type for Express Training Services?~~ | ~~1 client.~~ | **Included** — Course CPT added to Phase 1 |
| Q8 | Should we add `RealEstateListing` for Surfside Property Group? | 1 client. Would need a significant CPT with property-specific fields. | **Defer** |
| Q9 | Should Venue be a CPT or a page template? | The Event Room is the only venue client. | **Resolved** — page template |
| Q10 | Should we add a "Schema Preview" to the metabox showing the expected JSON-LD output? | Would help users verify their structured data before publishing. | **Defer to Phase 6** |
| Q11 | How should different tourism business models map to schema types? | Prime Time (BoatRental), Charter Boat Special K (FishingCharter), Sun Dogs (BoatTour). | **Resolved** — see Tourism Business Model Mapping section above |

---

## 15. Code Patterns to Follow

### CPT Registration (`MM_Schema_Post_Types`)

**TYPES constant** — map of slug => [schema_type_label, supports_array, dashicon]:
```php
private const TYPES = [
    'mm_event'    => [ 'Event',   [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'calendar-alt' ],
    'mm_service'  => [ 'Service', [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'hammer' ],
    'mm_how_to'   => [ 'HowTo',   [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'list-view' ],
    'mm_faq_page' => [ 'FAQPage', [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'editor' ],
    // New CPTs to add:
    'mm_trip'        => [ 'TouristTrip',        [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'location-alt' ],
    'mm_destination' => [ 'TouristDestination',  [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'location' ],
    'mm_vessel'      => [ 'Vehicle',             [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'car' ],
    'mm_course'      => [ 'Course',              [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'welcome-learn-more' ],
];
```

**`register_post_types()`** — loops TYPES, registers each:
```php
register_post_type( $slug, [
    'labels'       => [
        'name'               => $schema_type . 's',
        'singular_name'      => $schema_type,
        'add_new_item'       => 'Add New ' . $schema_type,
        'edit_item'          => 'Edit ' . $schema_type,
        'view_item'          => 'View ' . $schema_type,
        'search_items'       => 'Search ' . $schema_type . 's',
        'not_found'          => 'No ' . $schema_type . 's found',
        'not_found_in_trash' => 'No ' . $schema_type . 's found in Trash',
    ],
    'public'       => true,
    'has_archive'  => true,
    'rewrite'      => [ 'slug' => strtolower( str_replace( 'mm_', '', $slug ) ) ],
    'supports'     => $supports,
    'menu_icon'    => 'dashicons-' . $icon,
    'show_in_rest' => true,
] );
```

**Rewrite slugs for new CPTs:**
- `mm_trip` → `trip`
- `mm_destination` → `destination`
- `mm_vessel` → `vessel`
- `mm_course` → `course`

**`add_meta_boxes()`** — loops TYPES, adds `mm_schema_fields` metabox:
```php
add_meta_box(
    'mm_schema_fields',
    $schema_type . ' Details',
    [ __CLASS__, 'render_meta_box' ],
    $slug,
    'normal',
    'high'
);
```

**`save_meta()`** — saves `mm_schema_fields` array + `mm_breadcrumb_label` + `_mm_wc_product_id`:
```php
$raw_fields = (array) ( $_POST['mm_schema_fields'] ?? [] );
$fields     = [];
foreach ( $raw_fields as $key => $value ) {
    if ( is_array( $value ) ) { continue; }
    $fields[ sanitize_key( (string) $key ) ] = sanitize_text_field( wp_unslash( $value ) );
}
update_post_meta( $post_id, 'mm_schema_fields', $fields );
```

### Template Loading (`load_page_template()`)

**Current pattern** — only handles `page` post type with page templates:
```php
public static function load_page_template( string $template ): string {
    if ( ! is_singular( 'page' ) ) { return $template; }
    $post = get_post();
    if ( ! $post ) { return $template; }
    $page_template = get_page_template_slug( $post->ID );
    if ( ! isset( self::TEMPLATES[ $page_template ] ) ) { return $template; }
    $plugin_template = MM_META_DIR . 'templates/page-' . $page_template . '.php';
    if ( file_exists( $plugin_template ) ) { return $plugin_template; }
    return $template;
}
```

**New pattern needed** — add CPT single/archive template loading in the same `template_include` filter:
```php
// CPT single templates
if ( is_singular( 'mm_trip' ) ) {
    $tpl = MM_META_DIR . 'templates/single-mm_trip.php';
    if ( file_exists( $tpl ) ) { return $tpl; }
}
// CPT archive templates
if ( is_post_type_archive( 'mm_trip' ) ) {
    $tpl = MM_META_DIR . 'templates/archive-mm_trip.php';
    if ( file_exists( $tpl ) ) { return $tpl; }
}
```

### Schema Type Dispatch (`add_webpage_node()`)

**Content types list** — CPTs that get WebPage container + separate content node:
```php
// Line 139 — add new types here:
if ( in_array( $schema_type, [
    'BlogPosting', 'Article', 'HowTo', 'Product', 'Event', 'Service',
    'TouristTrip', 'Vehicle', 'Course'
], true ) ) {
    $type = 'WebPage';
}
```

**Same list appears at line 155** — update both locations.

### Content Node Skip List (`add_content_node()`)

**Types that skip content node** (already handled by WebPage node or elsewhere):
```php
// Line 416 — add TouristDestination here:
if ( in_array( $type, [
    'WebPage', 'WebSite', 'AboutPage', 'ContactPage', 'Calendar',
    'TouristDestination'
], true ) ) {
    return;
}
```

### `build_node_additions()` Pattern

Each type is a separate `if` block using `$str` helper and `$make_offer`:
```php
// ── TouristTrip ──────────────────────────────────────────────────
if ( 'TouristTrip' === $type ) {
    // tripOrigin (Place)
    $dep_name = $str( 'trip_departure_name' );
    if ( $dep_name ) {
        $place = [ '@type' => 'Place', 'name' => $dep_name ];
        if ( $str( 'trip_departure_address' ) ) {
            $place['address'] = $str( 'trip_departure_address' );
        }
        $lat = $str( 'trip_departure_lat' );
        $lng = $str( 'trip_departure_lng' );
        if ( $lat && $lng ) {
            $place['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng ];
        }
        $out['tripOrigin'] = $place;
    }
    // itinerary (ItemList of TouristDestination)
    $dest_name = $str( 'trip_destination_name' );
    if ( $dest_name ) {
        $out['itinerary'] = [
            '@type'           => 'ItemList',
            'itemListElement' => [
                [ '@type' => 'ListItem', 'position' => 1, 'item' => [ '@type' => 'TouristAttraction', 'name' => $dest_name ] ]
            ],
        ];
    }
    // duration
    if ( $str( 'trip_duration_iso' ) ) {
        $out['estimatedDuration'] = $str( 'trip_duration_iso' );
    }
    // capacity
    $passengers = $str( 'trip_max_passengers' );
    if ( $passengers && is_numeric( $passengers ) ) {
        $out['maximumAttendeeCapacity'] = (int) $passengers;
    }
    // touristType
    if ( $str( 'trip_tourist_type' ) ) {
        $types = array_map( 'trim', explode( ',', $str( 'trip_tourist_type' ) ) );
        $out['touristType'] = count( $types ) === 1 ? $types[0] : $types;
    }
    // departureTime / arrivalTime
    if ( $str( 'trip_departure_time' ) ) {
        $out['departureTime'] = $str( 'trip_departure_time' );
    }
    if ( $str( 'trip_arrival_time' ) ) {
        $out['arrivalTime'] = $str( 'trip_arrival_time' );
    }
    // offers
    $offer = $make_offer( 'trip_price', 'trip_currency' );
    if ( $offer ) {
        $booking = esc_url_raw( $str( 'trip_booking_url' ) );
        if ( $booking ) { $offer['url'] = $booking; }
        $out['offers'] = $offer;
    }
}
```

Same pattern for Vehicle, Course, TouristDestination.

### WooCommerce Product Linking Pattern

Used for Trip→Vessel relationship. Same pattern as Event/Service WC linking:
```php
// Meta key: _mm_vessel_id
// AJAX action: mm_search_vessels
// jQuery: search input + button + clear button
// Saves vessel post ID to post meta
```

### Sitemap Integration

**No code changes needed.** The sitemap admin UI uses `get_post_types(['public'=>true])` which automatically includes new CPTs. The `get_active_post_types()` method reads from `sitemap.post_types` setting. Users enable/disable CPTs in the Sitemap settings tab.

### Test Patterns

**Unit tests** extend `WP_UnitTestCase`:
```php
public function test_get_schema_types_has_tourist_trip(): void {
    $types = MM_Schema_Types::get_schema_types();
    $this->assertArrayHasKey( 'TouristTrip', $types );
}

public function test_build_node_additions_tourist_trip_with_price(): void {
    $fields = [
        'trip_departure_name' => 'Harborwalk Village',
        'trip_price'          => '350',
        'trip_currency'       => 'USD',
    ];
    $result = MM_Schema_Types::build_node_additions( $fields, 'TouristTrip' );
    $this->assertSame( 'Harborwalk Village', $result['tripOrigin']['name'] );
    $this->assertSame( '350', $result['offers']['price'] );
}
```

**Naming convention:** `test_build_node_additions_{type}_with_{scenario}`

---

## Schema.org References

- **TouristTrip:** https://schema.org/TouristTrip (10K–100K domains, growing)
- **TouristDestination:** https://schema.org/TouristDestination (10K–100K domains, growing)
- **Vehicle:** https://schema.org/Vehicle (10K–100K domains)
- **TourismBusiness:** https://schema.org/TourismBusiness (subtype of LocalBusiness)
- **BoatRental:** https://schema.org/BoatRental
- **BoatTrip:** https://schema.org/BoatTrip
- **FishingCharter:** https://schema.org/FishingCharter
- **TouristAttraction:** https://schema.org/TouristAttraction
- **Google structured data gallery:** https://developers.google.com/search/docs/appearance/structured-data/search-gallery
- **Google Local Business:** https://developers.google.com/search/docs/appearance/structured-data/local-business
- **Google Event:** https://developers.google.com/search/docs/appearance/structured-data/event
- **Google Review Snippet:** https://developers.google.com/search/docs/appearance/structured-data/review-snippet

---

*This document captures the complete research, design decisions, and implementation plan for MetaManager's schema expansion. Update as decisions are made and implementation progresses.*
