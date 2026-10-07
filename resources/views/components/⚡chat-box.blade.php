<?php

use App\Ai\Agents\ChatAgent;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    // State
    public string $input = '';

    public bool $loading = false;

    public array $messages = [];

    public ?string $conversationId = null;

    public array $initialMessages = [];

    public $attachment = null;

    public function mount(?string $conversationId = null, array $initialMessages = []): void
    {
        $this->conversationId = $conversationId;
        $this->initialMessages = $initialMessages;
        $this->messages = $initialMessages;
    }

    // Send message functionality
    public function send(): void
    {
        $this->validate([
            'input' => ['required_without:attachment', 'nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        $userMessage = $this->input;
        $this->input = '';
        $this->loading = true;

        // Handle Attachment - store permanently so StreamController can read it

        $attachmentPath = null;
        $attachmentName = null;
        $attachmentMime = null;

        if ($this->attachment) {
            $path = $this->attachment->store('chat-attachments', 'local');
            $attachmentPath = Storage::disk('local')->path($path);
            $attachmentMime = $this->attachment->getMimeType();
            $attachmentName = $this->attachment->getClientOriginalName();
            $this->attachment = null;
        }

        // User Prompt
        $this->messages[] = [
            'role' => 'user',
            'content' => $userMessage,
            'attachment_path' => $attachmentName ? $path : null, // Relative path for temporaryUrl
            'attachment_name' => $attachmentName,
            'attachment_mime' => $attachmentMime,
        ];

        try {
            // $response = new ChatAgent()->prompt($userMessage);

            // AI Response
            $this->messages[] = [
                'role' => 'assistant',
                'content' => '',
                'streaming' => true,
            ];

            // Dispatch browser event to start streaming
            $this->dispatch('start-stream', message: $userMessage, attachmentPath: $attachmentPath, attachmentMime: $attachmentMime);
        } catch (Throwable $e) {
            report($e);

            $this->messages[] = [
                'role' => 'assistant',
                'content' => 'Sorrry, something went wrong. Please try again!',
            ];
        }
    }

    // Clear chat
    #[On('new-conversation')]
    public function clearChat(): void
    {
        $this->messages = [];
        $this->input = '';
        $this->loading = false;

        // # Dispatch event to clear conversation id
        $this->dispatch('conversation-reset');
    }

    #[On('stream-complete')]
    public function onStreamComplete(string $content)
    {
        $lastIndex = array_key_last($this->messages);

        $this->messages[$lastIndex] = [
            'role' => 'assistant',
            'content' => $content,
            'streaming' => false,
        ];
        $this->loading = false;
    }

    #[On('stream-error')]
    public function onStreamError()
    {
        $lastIndex = array_key_last($this->messages);

        $this->messages[$lastIndex] = [
            'role' => 'assistant',
            'content' => 'Sorry, something went wrong. Please try again!',
            'streaming' => false,
        ];

        $this->loading = false;
    }
};
?>

<div class="flex-1 flex flex-col min-w-0 min-h-0 relative" x-data="chatStream" @mouseup="handleSelection"
    @mousedown.document="if(!$event.target.closest('#quote-btn')) showQuoteButton = false">

    {{-- Header --}}

    <header class="px-6 py-4 border-b border-zinc-800 bg-zinc-900/50 flex items-center justify-between flex-shrink-0">

        <div class="flex items-center gap-3">
            <button x-on:click="$dispatch('toggle-sidebar')" title="Toggle sidebar"
                class="text-zinc-500 hover:text-white transition-colors cursor-pointer rounded-md hover:bg-zinc-800 p-1">
                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                    <line x1="9" x2="9" y1="3" y2="21" />
                </svg>
            </button>
            <div>
                <h1 class="text-sm font-semibold text-white">New Conversation</h1>
                <p class="text-xs text-zinc-500 mt-0.5">Ask me anything</p>
            </div>
        </div>

        {{-- Clear button --}}
        <button wire:click="clearChat"
            class="text-xs text-zinc-500 hover:text-zinc-300 px-3 py-1.5 rounded-md hover:bg-zinc-800 transition-colors cursor-pointer">Clear</button>
    </header>

    <div class="flex-1 overflow-y-auto px-4 py-6 space-y-6 scroll-smooth" x-ref="messagesContainer" id="messages">
        {{-- Welcome screen --}}
        @if (count($messages) === 0)
            <div class="flex flex-col items-center justify-center h-full text-center py-20">

                <div
                    class="w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center text-2xl font-bold text-white shadow-2xl shadow-violet-900/40 mb-5">
                    LC
                </div>

                <h2 class="text-xl font-semibold text-white mb-2">Welcome to LaraChat</h2>
                <p class="text-zinc-400 text-sm max-w-sm leading-relaxed mb-7">AI assistant powered by Google Gemini &
                    Laravel 13
                    AI SDK</p>

                {{-- Suggestion chips --}}
                <div class="flex flex-wrap gap-2 justify-center max-w-sm">
                    @foreach (['What is Laravel 13?', 'Explain Livewire 4', 'Write a PHP function', 'Tell me a fun fact'] as $suggestion)
                        <button wire:click="$set('input', '{{ $suggestion }}')"
                            class="cursor-pointer px-3 py-1.5 rounded-full bg-zinc-800 border border-zinc-700 text-xs text-zinc-300 hover:border-violet-500 hover:text-white transition-all duration-200">{{ $suggestion }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Message lists --}}
        @foreach ($messages as $index => $message)
            {{-- User Prompt Message --}}
            @if ($message['role'] === 'user')
                <div class="flex justify-end group">
                    <div class="max-w-md lg:max-w-xl flex flex-col items-end">

                        @if (!empty($message['attachment_path'] ?? null))
                            @php
                                $isImage = str_starts_with($message['attachment_mime'] ?? '', 'image/');
                                $attachmentUrl = \Illuminate\Support\Facades\Storage::disk('local')->temporaryUrl(
                                    $message['attachment_path'],
                                    now()->addMinutes(30),
                                );
                            @endphp

                            @if ($isImage)
                                <a href="{{ $attachmentUrl }}" target="_blank" class="block">
                                    <img src="{{ $attachmentUrl }}" class="rounded-xl max-h-48 border border-zinc-700"
                                        alt="{{ $message['attachment_name'] }}" />

                                </a>
                            @else
                                <a href="{{ $attachmentUrl }}" target="_blank"
                                    class="flex items-center gap-2 bg-zinc-800 border border-zinc-700 rounded-xl px-3 py-2 text-xs text-zinc-200 hover:border-violet-500 transition-colors">
                                    <svg class="w-4 h-4 text-violet-400 flex-shrink-0" fill="none"
                                        stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>

                                    <span>{{ Str::limit($message['attachment_name'] ?? 'Document', 24) }}</span>
                                </a>
                            @endif
                        @endif

                        @if (filled($message['content']) && $message['content'] !== 'Please analyze this attachment.')
                            <div
                                class="bg-violet-600 text-white px-4 py-3 rounded-2xl rounded-tr-sm text-sm leading-relaxed">
                                {{ $message['content'] }}
                            </div>
                        @endif

                        {{-- Actions (Copy, Share, Edit) --}}
                        <div class="flex items-center gap-1 mt-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button title="Copy prompt" type="button"
                                x-on:click="copyPrompt(@js($message['content']), $event.currentTarget)"
                                class="text-zinc-500 hover:text-zinc-300 p-1.5 rounded-md hover:bg-zinc-800 transition-colors cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2">
                                    </rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                            </button>
                            <button title="Share prompt" type="button"
                                x-on:click="$dispatch('open-share-modal', { content: @js($message['content']) })"
                                class="text-zinc-500 hover:text-zinc-300 p-1.5 rounded-md hover:bg-zinc-800 transition-colors cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
                                    <polyline points="16 6 12 2 8 6"></polyline>
                                    <line x1="12" y1="2" x2="12" y2="15"></line>
                                </svg>
                            </button>
                            <button title="Edit prompt" type="button"
                                x-on:click="$wire.set('input', @js($message['content'])); document.querySelector('textarea').focus();"
                                class="text-zinc-500 hover:text-zinc-300 p-1.5 rounded-md hover:bg-zinc-800 transition-colors cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 20h9"></path>
                                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- AI Response Messages --}}
            @else
                <div class="flex items-start gap-3 group">

                    {{-- AI Avatar --}}
                    <div
                        class="w-7 h-7 rounded-full bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center text-white text-xs font-bold flex-shrink-0 mt-0.5">
                        AI
                    </div>

                    <div class="flex flex-col items-start w-full max-w-md lg:max-w-3xl">
                        {{-- AI Response Bubble --}}
                        <div
                            class="bg-zinc-800 border border-zinc-700/50 px-4 py-3 rounded-2xl rounded-tl-sm text-sm text-zinc-100 leading-relaxed w-full">
                            @if ($message['streaming'] ?? false)
                                {{-- Streaming Indicator --}}
                                <template x-if="!hasStartedStreaming">
                                    <span class="text-zinc-400">LaraChat is thinking... </span>
                                </template>

                                <span x-show="hasStartedStreaming" x-html="renderMarkdown(streamingText)"
                                    class="markdown-body block w-full"></span>
                            @else
                                {{-- Messages --}}
                                <span class="markdown-body block w-full"
                                    x-html="renderMarkdown(@js($message['content']))"></span>
                            @endif
                        </div>

                        {{-- AI Actions (Copy, Expand) --}}
                        @if (!($message['streaming'] ?? false))
                            <div
                                class="flex items-center gap-1 mt-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button title="Copy response" type="button"
                                    x-on:click="copyPrompt(@js($message['content']), $event.currentTarget)"
                                    class="text-zinc-500 hover:text-zinc-300 p-1.5 rounded-md hover:bg-zinc-800 transition-colors cursor-pointer">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="9" y="9" width="13" height="13" rx="2"
                                            ry="2"></rect>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                </button>
                                <button title="Expand response" type="button"
                                    x-on:click="$dispatch('open-expand-modal', { content: @js($message['content']) })"
                                    class="text-zinc-500 hover:text-zinc-300 p-1.5 rounded-md hover:bg-zinc-800 transition-colors cursor-pointer">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path
                                            d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3">
                                        </path>
                                    </svg>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @endforeach

    </div>

    {{-- Input text --}}
    <div class="flex-shrink-0 px-4 pb-5 pt-3 border-t border-zinc-800 bg-zinc-900/30">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-2 bg-zinc-800 border border-zinc-700 rounded-2xl px-4 py-3 focus-within:border-violet-500 transition-colors duration-200"
                :class="{ 'opacity-60 pointer-events-none': $wire.loading }">

                {{-- Textarea --}}
                <textarea :disabled="$wire.loading" wire:model="input" wire:keydown.enter.prevent="send" rows="1"
                    placeholder="Message Lara AI Chat Agent..."
                    class="flex-1 bg-transparent text-sm text-zinc-100 placeholder-zinc-500 resize-none outline-none leading-relaxed"
                    style="min-height: 28px; height: 28px;"
                    x-on:input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight, 144) + 'px' "></textarea>


                {{-- File attachment --}}
                <input type="file" wire:model="attachment" id="chat-attachment" class="hidden"
                    accept=".pdf,.jpg,.jpeg,.png" />

                {{-- File icon --}}
                <label for="chat-attachment"
                    class="cursor-pointer text-zinc-400 hover:text-white flex-shrink-0 w-8 h-8 rounded-xl hover:bg-zinc-700 flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4 text-zinc-400 hover:text-white" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 22 22">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                    </svg>
                </label>

                {{-- Preview --}}
                @if ($attachment)
                    <div wire:loading.remove wire:target="attachment"
                        class="flex items-center gap-2 bg-zinc-700/60 rounded-lg pl-1.5 pr-2 py-1 flex-shrink-0">

                        @if (str_starts_with($attachment->getMimeType(), 'image/'))
                            <img src="{{ $attachment->temporaryUrl() }}" class="w-8 h-8 rounded object-cover"
                                alt="Image Attachment" />
                        @else
                            <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor"
                                stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        @endif
                        <span
                            class="text-xs text-zinc-400 flex items-center gap-1 flex-shrink-0">{{ Str::limit($attachment->getClientOriginalName(), 20) }}
                        </span>
                        <button class="text-zinc-400 hover:text-white" type="button"
                            wire:click="$set('attachment', null)">X</button>

                    </div>
                @endif

                {{-- File uploading indicator --}}
                <div wire:loading wire:target="attachment" class="tex-xs text-zinc-500 flex-shrink-0 px-1">
                    Uploading...
                </div>

                {{-- Send button --}}
                <button wire:click="send"
                    :disabled="$wire.loading || (!$wire.input.trim() && {{ $attachment ? 'false' : 'true' }})"
                    class="cursor-pointer flex-shrink-0 w-8 h-8 rounded-xl bg-violet-600 hover:bg-violet-500 disabled:bg-zinc-700 disabled-opacity-50 disabled:cursor-not-allowed flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.269 20.876L5.999 12zm0 0h7.5" />
                    </svg>
                </button>
            </div>

            <p class="text-center text-xs text-zinc-600 mt-2">LaraChat can make mistakes. Always verify important info.
            </p>
        </div>
    </div>

    {{-- Floating Ask Chat Button --}}
    <div id="quote-btn" x-show="showQuoteButton" x-transition.opacity.duration.200ms style="display: none;"
        class="fixed z-50 transform -translate-x-1/2 -translate-y-full pb-2"
        :style="`left: ${selectionX}px; top: ${selectionY}px;`">
        <button @click="quoteSelection"
            class="bg-violet-600 text-white shadow-lg shadow-violet-900/20 text-xs font-semibold px-3 py-1.5 rounded-full flex items-center gap-1.5 hover:bg-violet-500 transition-all active:scale-95 cursor-pointer border border-violet-400/30">
            <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z">
                </path>
            </svg>
            Ask Chat
        </button>
    </div>


    {{-- Share Modal --}}
    <div x-data="shareModal"
        x-on:open-share-modal.window="shareContent = $event.detail.content; shareModalOpen = true"
        x-show="shareModalOpen" style="display: none;"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
        x-transition.opacity>

        <div class="bg-zinc-900 border border-zinc-800 rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden"
            x-on:click.outside="shareModalOpen = false" x-show="shareModalOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">

            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800">
                <h3 class="text-lg font-semibold text-white">Share prompt</h3>
                <button x-on:click="shareModalOpen = false"
                    class="text-zinc-400 hover:text-white transition-colors cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="p-6">
                {{-- Preview Box --}}
                <div class="bg-black rounded-xl p-6 mb-6 flex justify-end min-h-[160px]">
                    <div class="bg-zinc-800/80 text-zinc-200 text-sm px-4 py-3 rounded-2xl rounded-tr-sm max-w-[80%] inline-block h-fit"
                        x-text="shareContent">
                    </div>
                </div>

                {{-- Info --}}
                <div class="flex items-start gap-3 mb-8">
                    <svg class="w-5 h-5 text-zinc-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="2" y1="12" x2="22" y2="12"></line>
                        <path
                            d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z">
                        </path>
                    </svg>
                    <div>
                        <h4 class="text-sm font-semibold text-white">Share a public link to this prompt</h4>
                        <p class="text-xs text-zinc-400 mt-1">Anyone with this link can access this prompt. <a
                                href="#" class="text-zinc-300 underline">Learn more</a></p>
                    </div>
                </div>

                {{-- Social Buttons --}}
                <div class="flex justify-center gap-8">
                    <button type="button" x-on:click="copyShareLink($event)"
                        class="group flex flex-col items-center gap-2 cursor-pointer">
                        <div
                            class="w-12 h-12 rounded-full bg-zinc-800 flex items-center justify-center text-zinc-300 group-hover:bg-zinc-700 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                            </svg>
                        </div>
                        <span class="text-xs text-zinc-400 group-hover:text-zinc-300">Copy link</span>
                    </button>

                    <button type="button" x-on:click="shareOnX()"
                        class="group flex flex-col items-center gap-2 cursor-pointer">
                        <div
                            class="w-12 h-12 rounded-full bg-zinc-800 flex items-center justify-center text-zinc-300 group-hover:bg-zinc-700 transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
                            </svg>
                        </div>
                        <span class="text-xs text-zinc-400 group-hover:text-zinc-300">X</span>
                    </button>

                    <button type="button" x-on:click="shareOnLinkedIn()"
                        class="group flex flex-col items-center gap-2 cursor-pointer">
                        <div
                            class="w-12 h-12 rounded-full bg-zinc-800 flex items-center justify-center text-zinc-300 group-hover:bg-zinc-700 transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z" />
                            </svg>
                        </div>
                        <span class="text-xs text-zinc-400 group-hover:text-zinc-300">LinkedIn</span>
                    </button>

                    <button type="button" x-on:click="shareOnReddit()"
                        class="group flex flex-col items-center gap-2 cursor-pointer">
                        <div
                            class="w-12 h-12 rounded-full bg-zinc-800 flex items-center justify-center text-zinc-300 group-hover:bg-zinc-700 transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M24 11.5c0-1.65-1.35-3-3-3-.96 0-1.86.48-2.42 1.24-1.64-1-3.75-1.64-6.07-1.72.08-1.1.4-3.05 1.52-3.7.72-.4 1.73-.24 3 .5C17.2 6.3 18.46 7.5 20 7.5c1.65 0 3-1.35 3-3s-1.35-3-3-3c-1.38 0-2.54.94-2.88 2.22-1.43-.72-2.64-.8-3.6-.25-1.64.94-1.95 3.47-2 4.55-2.33.08-4.45.7-6.1 1.72C4.86 8.98 3.96 8.5 3 8.5c-1.65 0-3 1.35-3 3 0 1.32.84 2.44 2.05 2.84-.03.22-.05.44-.05.66 0 3.86 4.5 7 10 7s10-3.14 10-7c0-.22-.02-.44-.05-.66 1.2-.4 2.05-1.54 2.05-2.84zM2.3 13.37C1.5 13.07 1 12.35 1 11.5c0-1.1.9-2 2-2 .64 0 1.22.32 1.6.82-1.1.85-1.92 1.9-2.3 3.05zm3.7.13c0-1.1.9-2 2-2s2 .9 2 2-.9 2-2 2-2-.9-2-2zm9.8 4.8c-2.04 1.24-5.56 1.24-7.6 0-.2-.12-.28-.38-.16-.58.12-.2.38-.28.58-.16 1.7 1.02 4.9 1.02 6.6 0 .2-.12.46-.04.58.16.12.2.04.46-.16.58zm-1.8-2.8c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm6.3-1.05c-.38-1.15-1.2-2.2-2.3-3.05.38-.5.96-.82 1.6-.82 1.1 0 2 .9 2 2 0 .85-.5 1.57-1.3 1.87z" />
                            </svg>
                        </div>
                        <span class="text-xs text-zinc-400 group-hover:text-zinc-300">Reddit</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Expand Modal --}}
    <div x-data="{ expandModalOpen: false, expandContent: '' }"
        x-on:open-expand-modal.window="expandContent = $event.detail.content; expandModalOpen = true"
        x-show="expandModalOpen" style="display: none;"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
        x-transition.opacity>

        <div class="bg-zinc-900 border border-zinc-800 rounded-2xl shadow-2xl w-full max-w-5xl h-[85vh] flex flex-col overflow-hidden mx-4"
            x-on:click.outside="expandModalOpen = false" x-show="expandModalOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">

            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800 shrink-0">
                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-violet-500" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <path
                            d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3">
                        </path>
                    </svg>
                    Expanded View
                </h3>
                <button x-on:click="expandModalOpen = false"
                    class="text-zinc-400 hover:text-white transition-colors cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="p-8 overflow-y-auto flex-1 bg-zinc-950/50">
                <span class="markdown-body block text-base text-zinc-100 leading-relaxed max-w-4xl mx-auto"
                    x-html="renderMarkdown(expandContent)"></span>
            </div>
        </div>
    </div>
</div>

<style>
    .markdown-body h1,
    .markdown-body h2,
    .markdown-body h3 {
        font-weight: 600;
        margin: 0.75rem 0 0.5rem;
        color: #fff;
    }

    .markdown-body p {
        margin: 0.5rem 0;
    }

    .markdown-body ul,
    .markdown-body ol {
        margin: 0.5rem 0;
        padding-left: 1.25rem;
    }

    .markdown-body ul {
        list-style-type: disc;
    }

    .markdown-body ol {
        list-style-type: decimal;
    }

    .markdown-body strong {
        font-weight: 600;
        color: #fff;
    }

    .markdown-body code {
        background: rgb(39 39 42);
        padding: 0.1rem 0.3rem;
        border-radius: 0.25rem;
        font-size: 0.85rem;
    }

    .markdown-body .code-block pre {
        margin: 0;
        padding: 0;
        background: #09090b;
        overflow-x: auto;
    }

    .markdown-body .code-block pre code {
        background: transparent;
        padding: 1rem;
        border-radius: 0;
        display: block;
        line-height: 1.6;
        white-space: pre;
        font-size: 0.875rem;
    }

    .markdown-body .code-block.is-maximized {
        position: fixed;
        top: 3rem;
        left: 3rem;
        right: 3rem;
        bottom: 3rem;
        z-index: 100;
        display: flex;
        flex-direction: column;
        margin: 0;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 1);
        border-radius: 0.75rem;
    }

    .markdown-body .code-block.is-maximized pre {
        flex: 1;
        overflow: auto;
    }
</style>

@script
    <script>
        function escapeHtml(text) {
            return text.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        const renderer = new window.marked.Renderer();
        renderer.code = function(token) {
            const code = (token.text || '').replace(/^\n+|\n+$/g, '');
            const language = (token.lang || 'plaintext').trim();

            return `<div class="code-block my-3 rounded-xl overflow-hidden border border-zinc-700 bg-zinc-900">
                <div class="flex items-center justify-between px-4 py-2 bg-zinc-800 border-b border-zinc-700">
                    <span class="text-xs text-zinc-400 font-mono">${language}</span>
                    <div class="flex items-center gap-1.5">
                        <button type="button" data-maximize-code title="Expand code" class="cursor-pointer flex items-center justify-center text-zinc-400 hover:text-white p-1.5 rounded hover:bg-zinc-700 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path></svg>
                        </button>
                        <button type="button" data-copy-code title="Copy code" class="cursor-pointer flex items-center justify-center text-zinc-400 hover:text-white p-1.5 rounded hover:bg-zinc-700 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        </button>
                    </div>
                </div>
                <pre class="!m-0 !rounded-none bg-zinc-900 overflow-x-auto"><code class="hljs language-${language} block p-4 text-sm text-zinc-100 font-mono leading-relaxed">${escapeHtml(code)}</code></pre></div>`;
        };


        window.marked.use({
            renderer,
            breaks: true,
            gfm: true,
        });

        // Markdown
        window.renderMarkdown = function(text) {
            const html = DOMPurify.sanitize(window.marked.parse(text || ''));

            requestAnimationFrame(() => {
                document.querySelectorAll('.code-block pre code').forEach((block) => {
                    if (!window.hljs || block.dataset.highlighted) {
                        return;
                    }

                    window.hljs.highlightElement(block);
                });
            });

            return html;
        };

        // Action Buttons Click (Copy & Maximize)
        document.addEventListener('click', async (event) => {
            const copyBtn = event.target.closest('[data-copy-code]');
            if (copyBtn) {
                const code = copyBtn.closest('.code-block')?.querySelector('code')?.innerText || '';
                await navigator.clipboard.writeText(code);

                const svg = copyBtn.querySelector('svg');
                const originalSvg = svg.innerHTML;

                // Change to checkmark
                svg.innerHTML = '<polyline points="20 6 9 17 4 12"></polyline>';
                svg.classList.add('text-green-400');

                setTimeout(() => {
                    svg.innerHTML = originalSvg;
                    svg.classList.remove('text-green-400');
                }, 2000);
                return;
            }

            const maxBtn = event.target.closest('[data-maximize-code]');
            if (maxBtn) {
                const codeBlock = maxBtn.closest('.code-block');
                const isMaximized = codeBlock.classList.toggle('is-maximized');

                const svg = maxBtn.querySelector('svg');

                if (isMaximized) {
                    maxBtn.title = 'Collapse code';
                    svg.innerHTML = '<path d="M8 3v3h3M21 8h-3V5M3 21v-3h3M16 21v-3h3"></path>';
                } else {
                    maxBtn.title = 'Expand code';
                    svg.innerHTML =
                        '<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path>';
                }
                return;
            }
        });

        Alpine.data('shareModal', () => ({
            shareModalOpen: false,
            shareContent: '',

            getShareUrl() {
                return window.location.href;
            },
            copyShareLink($event) {
                const btn = $event.currentTarget;
                navigator.clipboard.writeText(this.getShareUrl());
                const span = btn.querySelector('span');
                const originalText = span.innerText;
                span.innerText = 'Copied!';
                setTimeout(() => {
                    span.innerText = originalText;
                }, 2000);
            },
            shareOnX() {
                const text = encodeURIComponent('Check out this prompt on LaraChat:\n\n"' + this.shareContent +
                    '"');
                const url = encodeURIComponent(this.getShareUrl());
                window.open(`https://twitter.com/intent/tweet?text=${text}&url=${url}`, '_blank',
                    'width=600,height=400');
            },
            shareOnLinkedIn() {
                const url = encodeURIComponent(this.getShareUrl());
                window.open(`https://www.linkedin.com/sharing/share-offsite/?url=${url}`, '_blank',
                    'width=600,height=600');
            },
            shareOnReddit() {
                const url = encodeURIComponent(this.getShareUrl());
                const title = encodeURIComponent('Interesting prompt on LaraChat');
                window.open(`https://reddit.com/submit?url=${url}&title=${title}`, '_blank',
                    'width=600,height=600');
            }
        }));

        Alpine.data('chatStream', () => ({
            streamingText: '',
            hasStartedStreaming: false,
            conversationId: @js($conversationId),
            selectionText: '',
            selectionX: 0,
            selectionY: 0,
            showQuoteButton: false,

            handleSelection() {
                setTimeout(() => {
                    const selection = window.getSelection();
                    const text = selection.toString().trim();
                    if (text && text.length > 0) {
                        // Only show if the selection is inside the chat messages
                        if (!this.$refs.messagesContainer.contains(selection.anchorNode)) {
                            return;
                        }
                        const range = selection.getRangeAt(0);
                        const rect = range.getBoundingClientRect();

                        this.selectionText = text;
                        this.selectionX = rect.left + (rect.width / 2);
                        this.selectionY = rect.top;
                        this.showQuoteButton = true;
                    } else {
                        this.showQuoteButton = false;
                    }
                }, 10);
            },

            async quoteSelection() {
                const currentInput = await this.$wire.get('input') || '';
                const quote = `> ${this.selectionText}\n\n`;
                const newInput = currentInput ? currentInput + '\n' + quote : quote;

                this.$wire.set('input', newInput);
                this.showQuoteButton = false;
                window.getSelection().removeAllRanges();

                setTimeout(() => {
                    const textarea = document.querySelector('textarea');
                    if (textarea) {
                        textarea.focus();
                        textarea.dispatchEvent(new Event('input')); // trigger height adjustment
                    }
                }, 100);
            },

            copyPrompt(text, btn) {
                navigator.clipboard.writeText(text);
                const originalHtml = btn.innerHTML;
                btn.innerHTML =
                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-green-500"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                }, 2000);
            },

            init() {
                this.$wire.on('start-stream', ({
                    message,
                    attachmentPath,
                    attachmentMime
                }) => {
                    this.startStream(message, attachmentPath, attachmentMime);
                });

                // Listening event
                this.$wire.on('conversation-reset', () => {
                    this.conversationId = null;
                    this.streamingText = '';
                    this.hasStartedStreaming = false;
                });

                // Scroll to bottom
                this.scrollToBottom();

                setTimeout(() => {
                    this.scrollToBottom();
                }, 100);
            },

            // Function start streaming
            async startStream(message, attachmentPath = null, attachmentMime = null) {
                this.streamingText = '';
                this.hasStartedStreaming = false;

                // Force scroll when starting a new stream to see the user message
                this.scrollToBottom(true);

                console.log('Sending conversation id: ', this.conversationId, ' and attachment ',
                    attachmentPath);

                try {
                    const response = await fetch('{{ route('chat.stream') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'text/event-stream',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                .content,
                        },
                        body: JSON.stringify({
                            message: message,
                            conversation_id: this.conversationId,
                            attachment_path: attachmentPath,
                            attachment_mime: attachmentMime,
                        }),
                    });

                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();

                    let buffer = '';

                    while (true) {
                        const {
                            done,
                            value
                        } = await reader.read();

                        if (done) break;

                        buffer += decoder.decode(value, {
                            stream: true,
                        });

                        const lines = buffer.split('\n');
                        buffer = lines.pop();

                        for (const line of lines) {

                            if (!line.startsWith('data: ')) {
                                continue;
                            }

                            const data = line.slice(6).trim();

                            if (data === '[DONE]') {
                                this.$wire.dispatch('stream-complete', {
                                    content: this.streamingText,
                                });

                                return;
                            }

                            try {

                                const parsed = JSON.parse(data);

                                if (parsed.content) {
                                    this.hasStartedStreaming = true;
                                    this.streamingText += parsed.content;

                                    // Auto scroll to latest message/response only if near bottom
                                    this.scrollToBottom(false);
                                }

                                // Capture conversation id
                                if (parsed.conversation_id) {
                                    this.conversationId = parsed.conversation_id;

                                    // Dispatch event to refresh the sidebar
                                    Livewire.dispatch('conversation-saved', {
                                        conversationId: this.conversationId
                                    });

                                    console.log('Stored conversation id: ', this.conversationId);
                                }

                                if (parsed.error) {
                                    this.$wire.dispatch('stream-error');
                                    return;
                                }

                            } catch (error) {
                                console.error('Invalid stream data: ', error);
                            }
                        }
                    }

                    this.$wire.dispatch('stream-complete', {
                        content: this.streamingText,
                    });

                } catch (error) {
                    console.error(error);
                    this.$wire.dispatch('stream-error');
                }
            },

            scrollToBottom(force = false) {
                this.$nextTick(() => {
                    const container = this.$refs.messagesContainer;
                    if (!container) return;

                    // Check if we are near the bottom (within 150px)
                    const isNearBottom = container.scrollHeight - container.scrollTop - container
                        .clientHeight < 150;

                    if (force || isNearBottom) {
                        container.scrollTop = container.scrollHeight;
                        requestAnimationFrame(() => {
                            container.scrollTop = container.scrollHeight;
                        });
                    }
                });
            },
        }));
    </script>
@endscript
