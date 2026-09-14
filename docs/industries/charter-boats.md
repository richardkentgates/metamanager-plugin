# Charter Boats & Tourism Industry Guide

Guide for charter boat companies, tour operators, parasailing businesses, and other tourism operations using MetaManager.

## Business Profile Setup

Set your Business Profile type in **MetaManager > Business**:

| Business Model | Schema Type | Example |
|---------------|-------------|---------|
| Boat rental with captain | `BoatRental` | Prime Time Charters |
| Fishing charter | `FishingCharter` | Charter Boat Special K |
| Parasailing / adventure activity | `BoatTour` | Sun Dogs Parasail |
| Sailing tour | `BoatTour` | Sail Away Destin |
| Generic tourism | `TourismBusiness` | — |

## Content Types to Use

### Trips (mm_trip)

Create a Trip for each bookable experience:
- 4 Hour Crab Island Pontoon Charter
- Deep Sea Fishing Charter
- Parasailing Adventure
- Dolphin Cruise
- Sunset Sail

**Key fields:**
- Departure Location — where customers board
- Destination — where the trip goes
- Duration — how long the trip lasts
- Max Passengers — capacity
- Price — cost per trip/person
- Booking URL — link to your booking system (FareHarbor, etc.)
- Tourist Type — "Boat rental", "Fishing charter", "Adventure activity", etc.

### Vessels (mm_vessel)

Create a Vessel for each boat in your fleet:
- 2022 Barletta Cabrio 200HP
- 2022 Barletta Lusso 250HP
- 40ft Bertram Widebody
- 31ft Ocean Pro

**Key fields:**
- Year, Length, Engine, Max Passengers, Type, Manufacturer, Amenities

Link Trips to Vessels using the Vessel selector on the Trip edit screen.

### Destinations (mm_destination)

Create a Destination for each place your trips visit:
- Crab Island, Destin
- Destin Harbor
- Choctawhatchee Bay
- Gulf of Mexico

**Key fields:**
- Address, Coordinates, Tourist Type, Attractions, Map URL

Link Trips to Destinations using the Destination selector on the Trip edit screen.

### Events (mm_event)

Create Events for seasonal happenings:
- Destin Fishing Rodeo (October)
- Harborwalk Party
- Sunset Concert Series

Events have specific dates. Trips are standing offerings. They coexist.

## Schema Output

When properly configured, your site will emit:

1. **LocalBusiness** node (site-wide) with your business type (BoatRental, FishingCharter, BoatTour)
2. **TouristTrip** nodes for each trip with departure, destination, pricing, capacity
3. **Vehicle** nodes for each vessel with specs and amenities
4. **TouristDestination** nodes for each destination with coordinates and attractions
5. **Event** nodes for seasonal events with dates and location

All nodes cross-reference each other via `@id` links in the JSON-LD graph.

## Example: Prime Time Charters Setup

1. **Business Profile:** Type = `BoatRental`, Name = "Prime Time Charters"
2. **Vessels:**
   - 2022 Barletta Cabrio 200HP (24ft, 13 passengers)
   - 2022 Barletta Cabrio 150HP (24ft, 13 passengers)
   - 2022 Barletta Lusso 250HP (24ft, 13 passengers)
3. **Destinations:**
   - Crab Island, Destin
   - Destin Harbor
4. **Trips:**
   - 4 Hour Pontoon Rental → links to Crab Island destination, any vessel
   - 6 Hour Pontoon Rental → links to Crab Island destination, any vessel
   - 8 Hour Pontoon Rental → links to Crab Island destination, any vessel
   - Sunset Cruise → links to Destin Harbor destination
5. **Events:**
   - Destin Fishing Rodeo (if participating)

## Example: Sun Dogs Parasail Setup

1. **Business Profile:** Type = `BoatTour`, Name = "Sun Dogs Parasailing"
2. **Vessels:**
   - 31ft Ocean Pro (6 passengers)
3. **Destinations:**
   - Destin Harbor
   - Gulf of Mexico
4. **Trips:**
   - Parasailing Adventure → touristType: "Adventure activity", "Water sports"
   - Private Dolphin Cruise → touristType: "Wildlife tourism"
   - Media Package Add-On → separate trip or service
