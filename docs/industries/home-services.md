# Home Services Industry Guide

Guide for contractors, landscapers, movers, and other home service businesses using MetaManager.

## Business Profile Setup

Set your Business Profile type in **MetaManager > Business**:

| Business Model | Schema Type | Example |
|---------------|-------------|---------|
| General contractor | `GeneralContractor` | Suncrete, Texas Star Restoration |
| Landscaping | `LandscapingBusiness` | ScenicScape, Rye-Land Lawn Care |
| Moving company | `MovingCompany` | Professional Moving Services |
| Roofing | `RoofingContractor` | Gutter Tymer |
| Awning installation | `GeneralContractor` | Delta Awnings |
| Garden center | `LandscapingBusiness` or `Store` | Sundrop Gardens |

## Content Types to Use

### Services (mm_service)

Create a Service for each offering:
- Gutter Installation
- Concrete Work
- Lawn Care
- Moving Services
- Awning Installation
- Roof Repair

**Key fields:**
- Service Type — category of service
- Area Served — geographic area (e.g. "Fort Walton Beach, FL")
- Price — starting price or range
- Booking URL — link to request a quote

### HowTo (mm_how_to)

Create HowTo guides for content marketing:
- How to Choose the Right Gutter System
- How to Prepare for a Move
- How to Maintain Your Lawn

### FAQPage (mm_faq_page)

Create FAQ pages for common questions:
- Gutter FAQ
- Moving FAQ
- Landscaping FAQ

## Schema Output

When properly configured, your site will emit:

1. **LocalBusiness** node (site-wide) with your specific business type
2. **Service** nodes for each service with type, area, pricing
3. **HowTo** nodes for guides with steps, supplies, tools
4. **FAQPage** nodes for Q&A content

## Example: Gutter Tymer Setup

1. **Business Profile:** Type = `RoofingContractor`, Name = "Gutter Tymer"
2. **Services:**
   - Gutter Installation — area: "Fort Walton Beach, Destin, FL"
   - Gutter Repair
   - Gutter Cleaning
3. **HowTo:**
   - How to Choose the Right Gutter System
4. **FAQ:**
   - Gutter Installation FAQ
