@if ($row->primary_image_thumb_url)
    <img
        src="{{ $row->primary_image_thumb_url }}"
        @if ($row->primary_image_thumb_srcset) srcset="{{ $row->primary_image_thumb_srcset }}" sizes="40px" @endif
        alt="Image"
        class="h-10 w-10 rounded-md border border-base-200 object-cover"
        loading="lazy"
    />
@else
    <div class="flex h-10 w-10 items-center justify-center rounded-md border border-base-200 bg-base-100 text-base-content/30">
        <x-tallui-icon name="o-photo" class="h-5 w-5" />
    </div>
@endif
