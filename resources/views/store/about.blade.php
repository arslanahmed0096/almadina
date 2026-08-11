@extends('layouts.store')

@section('content')
@php
  $heroImage = asset('images/storefront/almadina-appliances-hero-v1.webp');
  $storyImage = asset('images/storefront/almadina-branch-01.webp');

  $stats = [
    ['icon' => 'award', 'value' => '10+', 'label' => 'Years of Trust'],
    ['icon' => 'store', 'value' => '4+', 'label' => 'Store Locations'],
    ['icon' => 'shield-check', 'value' => '50+', 'label' => 'Trusted Brands'],
    ['icon' => 'users', 'value' => 'Thousands', 'label' => 'of Happy Customers'],
  ];

  $drivers = [
    [
      'icon' => 'target',
      'title' => 'Our Mission',
      'text' => 'To make genuine technology and home appliances accessible to every Pakistani home with honesty, value and care.',
      'tone' => 'rose',
    ],
    [
      'icon' => 'binoculars',
      'title' => 'Our Vision',
      'text' => 'To be Pakistan\'s most trusted destination for electronics and home appliances, known for quality and service.',
      'tone' => 'gold',
    ],
    [
      'icon' => 'handshake',
      'title' => 'Our Promise',
      'text' => 'Genuine products, official warranty and reliable support before, during and after your purchase.',
      'tone' => 'rose',
    ],
  ];

  $values = [
    ['icon' => 'shield-check', 'title' => 'Trust & Transparency', 'text' => 'We believe in honest information and clear communication at every step of your journey.'],
    ['icon' => 'gem', 'title' => 'Quality First', 'text' => 'We partner with leading global brands to ensure top quality products for your home.'],
    ['icon' => 'user', 'title' => 'Customer Commitment', 'text' => 'Your satisfaction is our priority. We listen, understand and deliver the best.'],
    ['icon' => 'headset', 'title' => 'Reliable Service', 'text' => 'From expert guidance to after-sales support, we are always here for you.'],
  ];

  $journey = [
    ['year' => '2013', 'title' => 'Humble Beginning', 'text' => 'Al Madina was founded with a simple goal to bring trusted appliances to local communities.'],
    ['year' => '2016', 'title' => 'Growing Together', 'text' => 'Opened more branches and expanded our range to serve more households.'],
    ['year' => '2019', 'title' => 'Stronger Partnerships', 'text' => 'Partnered with leading global brands to ensure authenticity and official warranty.'],
    ['year' => 'Today', 'title' => 'Moving Forward', 'text' => 'Continuing our journey with better service, more choices and happier customers.'],
  ];

  $reasons = [
    ['icon' => 'shield-check', 'title' => 'Genuine Products', 'text' => '100% authentic products from trusted brands.'],
    ['icon' => 'award', 'title' => 'Official Warranty', 'text' => 'Official brand warranty for complete peace of mind.'],
    ['icon' => 'truck', 'title' => 'Fast Delivery', 'text' => 'Quick and reliable delivery across Pakistan.'],
    ['icon' => 'headset', 'title' => 'Customer Support', 'text' => 'Helpful support before and after your purchase.'],
  ];
@endphp

<main class="alm-about-page">
  @include('store.partials.almadina.about.breadcrumb')
  @include('store.partials.almadina.about.hero')
  @include('store.partials.almadina.about.introduction')
  @include('store.partials.almadina.about.stats')
  @include('store.partials.almadina.about.purpose')
  @include('store.partials.almadina.about.values')
  @include('store.partials.almadina.about.journey')
  @include('store.partials.almadina.about.stores')
  @include('store.partials.almadina.about.why-shop')
  @include('store.partials.almadina.about.cta')
</main>
@endsection
