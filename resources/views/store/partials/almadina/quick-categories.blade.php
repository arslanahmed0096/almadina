<section class="alm-quick-categories" aria-label="Shop appliance categories">
  @foreach(collect($homeCategories)->take(6) as $category)
    <a href="{{ route('store.shop', ['category' => $category->id]) }}" class="alm-category-card">
      <span><img src="{{ $category->home_image_url }}" alt="{{ $category->name }}" width="220" height="150" loading="eager" decoding="async"></span>
      <strong>{{ $category->name }}</strong>
    </a>
  @endforeach
</section>
