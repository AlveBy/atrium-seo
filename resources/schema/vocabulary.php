<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

/*
 * The part of the schema.org vocabulary the modules use, for
 * Atrium\Seo\Schema\Validator: each type's parents, the properties it adds
 * and the ones search engines require of it. A type has every property of
 * its parents. Extend it from a module's tests:
 *
 *     Validator::extend(['ExerciseGym' => ['parents' => ['SportsActivityLocation']]]);
 */

return [
    'Thing' => ['properties' => ['name', 'description', 'url', 'image', 'sameAs', 'identifier', 'alternateName', 'mainEntityOfPage', 'potentialAction', 'disambiguatingDescription', 'subjectOf']],

    // Creative works and pages
    'CreativeWork' => ['parents' => ['Thing'], 'properties' => ['author', 'publisher', 'datePublished', 'dateModified', 'dateCreated', 'headline', 'inLanguage', 'keywords', 'offers', 'aggregateRating', 'review', 'about', 'mainEntity', 'text', 'thumbnailUrl', 'isPartOf', 'hasPart', 'copyrightHolder', 'copyrightYear', 'license', 'genre', 'creator', 'contentLocation', 'audience', 'abstract']],
    'WebSite' => ['parents' => ['CreativeWork']],
    'WebPage' => ['parents' => ['CreativeWork'], 'properties' => ['breadcrumb', 'primaryImageOfPage', 'speakable', 'lastReviewed', 'relatedLink', 'significantLink']],
    'FAQPage' => ['parents' => ['WebPage'], 'required' => ['mainEntity']],
    'ItemPage' => ['parents' => ['WebPage']],
    'CollectionPage' => ['parents' => ['WebPage']],
    'AboutPage' => ['parents' => ['WebPage']],
    'ContactPage' => ['parents' => ['WebPage']],
    'RealEstateListing' => ['parents' => ['WebPage'], 'properties' => ['datePosted', 'leaseLength']],
    'Article' => ['parents' => ['CreativeWork'], 'properties' => ['articleBody', 'articleSection', 'wordCount', 'backstory'], 'required' => ['headline']],
    'NewsArticle' => ['parents' => ['Article'], 'properties' => ['dateline', 'printSection', 'printEdition']],
    'SocialMediaPosting' => ['parents' => ['Article'], 'properties' => ['sharedContent']],
    'BlogPosting' => ['parents' => ['SocialMediaPosting']],
    'Blog' => ['parents' => ['CreativeWork'], 'properties' => ['blogPost']],
    'Question' => ['parents' => ['CreativeWork'], 'properties' => ['acceptedAnswer', 'suggestedAnswer', 'answerCount'], 'required' => ['name', 'acceptedAnswer']],
    'Answer' => ['parents' => ['CreativeWork'], 'required' => ['text']],
    'Review' => ['parents' => ['CreativeWork'], 'properties' => ['reviewRating', 'reviewBody', 'itemReviewed', 'positiveNotes', 'negativeNotes'], 'required' => ['author']],
    'ImageObject' => ['parents' => ['CreativeWork'], 'properties' => ['contentUrl', 'width', 'height', 'caption', 'encodingFormat']],

    // Lists
    'ItemList' => ['parents' => ['Thing'], 'properties' => ['itemListElement', 'numberOfItems', 'itemListOrder']],
    'BreadcrumbList' => ['parents' => ['ItemList'], 'required' => ['itemListElement']],
    'ListItem' => ['parents' => ['Thing'], 'properties' => ['position', 'item', 'nextItem', 'previousItem'], 'required' => ['position']],

    // Actions
    'Action' => ['parents' => ['Thing'], 'properties' => ['target', 'object', 'result', 'agent']],
    'SearchAction' => ['parents' => ['Action'], 'properties' => ['query-input', 'query'], 'required' => ['target']],
    'EntryPoint' => ['parents' => ['Thing'], 'properties' => ['urlTemplate', 'actionPlatform', 'contentType']],

    // Organizations and people
    'Organization' => ['parents' => ['Thing'], 'properties' => ['logo', 'telephone', 'email', 'address', 'contactPoint', 'aggregateRating', 'review', 'legalName', 'foundingDate', 'founder', 'areaServed', 'location', 'member', 'employee', 'department', 'parentOrganization', 'taxID', 'vatID', 'brand', 'hasOfferCatalog', 'makesOffer', 'numberOfEmployees', 'slogan', 'knowsLanguage']],
    'Corporation' => ['parents' => ['Organization'], 'properties' => ['tickerSymbol']],
    'NGO' => ['parents' => ['Organization']],
    'GovernmentOrganization' => ['parents' => ['Organization']],
    'EducationalOrganization' => ['parents' => ['Organization'], 'properties' => ['alumni']],
    'MedicalOrganization' => ['parents' => ['Organization'], 'properties' => ['medicalSpecialty', 'healthPlanNetworkId', 'isAcceptingNewPatients']],
    'Person' => ['parents' => ['Thing'], 'properties' => ['givenName', 'familyName', 'telephone', 'email', 'jobTitle', 'worksFor', 'address', 'affiliation', 'alumniOf', 'award', 'birthDate', 'gender', 'honorificPrefix', 'knowsAbout', 'knowsLanguage', 'nationality']],
    'Brand' => ['parents' => ['Thing'], 'properties' => ['logo', 'slogan', 'aggregateRating', 'review']],
    'ContactPoint' => ['parents' => ['Thing'], 'properties' => ['telephone', 'email', 'contactType', 'areaServed', 'availableLanguage', 'hoursAvailable']],

    // Places
    'Place' => ['parents' => ['Thing'], 'properties' => ['address', 'geo', 'telephone', 'hasMap', 'aggregateRating', 'review', 'openingHoursSpecification', 'amenityFeature', 'containedInPlace', 'photo', 'maximumAttendeeCapacity', 'publicAccess', 'smokingAllowed', 'faxNumber']],
    'LocalBusiness' => ['parents' => ['Organization', 'Place'], 'properties' => ['openingHours', 'priceRange', 'currenciesAccepted', 'paymentAccepted'], 'required' => ['name', 'address']],
    'Store' => ['parents' => ['LocalBusiness']],
    'FoodEstablishment' => ['parents' => ['LocalBusiness'], 'properties' => ['servesCuisine', 'menu', 'hasMenu', 'acceptsReservations', 'starRating']],
    'Restaurant' => ['parents' => ['FoodEstablishment']],
    'LodgingBusiness' => ['parents' => ['LocalBusiness'], 'properties' => ['checkinTime', 'checkoutTime', 'numberOfRooms', 'petsAllowed', 'starRating', 'availableLanguage', 'audience']],
    'Hotel' => ['parents' => ['LodgingBusiness']],
    'Resort' => ['parents' => ['LodgingBusiness']],
    'Hostel' => ['parents' => ['LodgingBusiness']],
    'MedicalBusiness' => ['parents' => ['LocalBusiness'], 'properties' => ['medicalSpecialty', 'availableService', 'isAcceptingNewPatients']],
    'MedicalClinic' => ['parents' => ['MedicalBusiness', 'MedicalOrganization']],
    'Physician' => ['parents' => ['MedicalBusiness', 'MedicalOrganization'], 'properties' => ['hospitalAffiliation', 'occupationalCategory']],
    'Dentist' => ['parents' => ['MedicalBusiness', 'MedicalOrganization']],
    'ProfessionalService' => ['parents' => ['LocalBusiness']],
    'LegalService' => ['parents' => ['LocalBusiness']],
    'TravelAgency' => ['parents' => ['LocalBusiness']],
    'RealEstateAgent' => ['parents' => ['LocalBusiness']],
    'AutoDealer' => ['parents' => ['LocalBusiness']],
    'SportsActivityLocation' => ['parents' => ['LocalBusiness']],
    'Accommodation' => ['parents' => ['Place'], 'properties' => ['numberOfRooms', 'numberOfBedrooms', 'numberOfBathroomsTotal', 'numberOfFullBathrooms', 'floorSize', 'floorLevel', 'petsAllowed', 'accommodationCategory', 'yearBuilt', 'occupancy', 'bed', 'permittedUsage', 'tourBookingPage']],
    'Room' => ['parents' => ['Accommodation']],
    'HotelRoom' => ['parents' => ['Room']],
    'Apartment' => ['parents' => ['Accommodation']],
    'House' => ['parents' => ['Accommodation']],
    'SingleFamilyResidence' => ['parents' => ['House']],
    'TouristAttraction' => ['parents' => ['Place'], 'properties' => ['availableLanguage', 'touristType']],
    'TouristDestination' => ['parents' => ['Place'], 'properties' => ['includesAttraction', 'touristType']],
    'AdministrativeArea' => ['parents' => ['Place']],
    'Country' => ['parents' => ['AdministrativeArea']],
    'City' => ['parents' => ['AdministrativeArea']],
    'PostalAddress' => ['parents' => ['ContactPoint'], 'properties' => ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry', 'postOfficeBoxNumber']],
    'GeoCoordinates' => ['parents' => ['Thing'], 'properties' => ['latitude', 'longitude', 'elevation', 'address', 'postalCode', 'addressCountry'], 'required' => ['latitude', 'longitude']],
    'VirtualLocation' => ['parents' => ['Thing']],

    // Products and offers
    'Product' => ['parents' => ['Thing'], 'properties' => ['offers', 'brand', 'sku', 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14', 'mpn', 'aggregateRating', 'review', 'category', 'color', 'model', 'material', 'weight', 'width', 'height', 'depth', 'manufacturer', 'releaseDate', 'productID', 'additionalProperty', 'isRelatedTo', 'isSimilarTo', 'itemCondition', 'logo', 'size', 'audience'], 'required' => ['name']],
    'Vehicle' => ['parents' => ['Product'], 'properties' => ['mileageFromOdometer', 'vehicleIdentificationNumber', 'vehicleEngine', 'fuelType', 'vehicleTransmission', 'productionDate', 'modelDate', 'bodyType', 'driveWheelConfiguration', 'numberOfDoors', 'vehicleSeatingCapacity', 'vehicleModelDate']],
    'Car' => ['parents' => ['Vehicle']],
    'Offer' => ['parents' => ['Thing'], 'properties' => ['price', 'priceCurrency', 'availability', 'itemCondition', 'priceValidUntil', 'validFrom', 'validThrough', 'seller', 'offeredBy', 'itemOffered', 'priceSpecification', 'category', 'eligibleRegion', 'areaServed', 'availabilityStarts', 'availabilityEnds', 'inventoryLevel', 'shippingDetails', 'hasMerchantReturnPolicy', 'businessFunction', 'leaseLength', 'sku', 'gtin', 'mpn', 'aggregateRating', 'review']],
    'AggregateOffer' => ['parents' => ['Offer'], 'properties' => ['lowPrice', 'highPrice', 'offerCount', 'offers']],
    'OfferCatalog' => ['parents' => ['ItemList']],
    'Service' => ['parents' => ['Thing'], 'properties' => ['serviceType', 'provider', 'areaServed', 'offers', 'aggregateRating', 'review', 'brand', 'category', 'hasOfferCatalog', 'audience', 'availableChannel', 'termsOfService']],
    'PriceSpecification' => ['parents' => ['Thing'], 'properties' => ['price', 'priceCurrency', 'minPrice', 'maxPrice', 'validFrom', 'validThrough', 'valueAddedTaxIncluded']],
    'UnitPriceSpecification' => ['parents' => ['PriceSpecification'], 'properties' => ['unitCode', 'unitText', 'referenceQuantity', 'billingDuration', 'priceType']],
    'MonetaryAmount' => ['parents' => ['Thing'], 'properties' => ['currency', 'value', 'minValue', 'maxValue', 'validFrom', 'validThrough']],
    'QuantitativeValue' => ['parents' => ['Thing'], 'properties' => ['value', 'minValue', 'maxValue', 'unitCode', 'unitText']],
    'PropertyValue' => ['parents' => ['Thing'], 'properties' => ['value', 'propertyID', 'unitCode', 'unitText', 'minValue', 'maxValue']],
    'LocationFeatureSpecification' => ['parents' => ['PropertyValue'], 'properties' => ['hoursAvailable', 'validFrom', 'validThrough']],
    'OpeningHoursSpecification' => ['parents' => ['Thing'], 'properties' => ['dayOfWeek', 'opens', 'closes', 'validFrom', 'validThrough']],

    // Ratings
    'Rating' => ['parents' => ['Thing'], 'properties' => ['ratingValue', 'bestRating', 'worstRating', 'author', 'ratingExplanation'], 'required' => ['ratingValue']],
    'AggregateRating' => ['parents' => ['Rating'], 'properties' => ['ratingCount', 'reviewCount', 'itemReviewed']],

    // Events and trips
    'Event' => ['parents' => ['Thing'], 'properties' => ['startDate', 'endDate', 'doorTime', 'duration', 'location', 'eventStatus', 'eventAttendanceMode', 'organizer', 'performer', 'offers', 'aggregateRating', 'review', 'inLanguage', 'isAccessibleForFree', 'maximumAttendeeCapacity', 'remainingAttendeeCapacity', 'previousStartDate', 'subEvent', 'superEvent', 'eventSchedule', 'audience', 'sponsor', 'typicalAgeRange'], 'required' => ['name', 'startDate', 'location']],
    'BusinessEvent' => ['parents' => ['Event']],
    'EducationEvent' => ['parents' => ['Event'], 'properties' => ['educationalLevel', 'teaches']],
    'Festival' => ['parents' => ['Event']],
    'MusicEvent' => ['parents' => ['Event']],
    'SportsEvent' => ['parents' => ['Event'], 'properties' => ['homeTeam', 'awayTeam', 'competitor']],
    'TheaterEvent' => ['parents' => ['Event']],
    'Schedule' => ['parents' => ['Thing'], 'properties' => ['startDate', 'endDate', 'repeatFrequency', 'byDay', 'startTime', 'endTime', 'scheduleTimezone']],
    'Trip' => ['parents' => ['Thing'], 'properties' => ['itinerary', 'offers', 'provider', 'subTrip', 'partOfTrip', 'departureTime', 'arrivalTime', 'tripOrigin']],
    'TouristTrip' => ['parents' => ['Trip'], 'properties' => ['touristType'], 'required' => ['name']],

    // Jobs
    'JobPosting' => ['parents' => ['Thing'], 'properties' => ['title', 'datePosted', 'validThrough', 'employmentType', 'hiringOrganization', 'jobLocation', 'jobLocationType', 'applicantLocationRequirements', 'baseSalary', 'directApply', 'industry', 'occupationalCategory', 'qualifications', 'responsibilities', 'skills', 'workHours', 'jobBenefits', 'educationRequirements', 'experienceRequirements', 'totalJobOpenings', 'jobStartDate'], 'required' => ['title', 'description', 'datePosted', 'hiringOrganization']],
];
