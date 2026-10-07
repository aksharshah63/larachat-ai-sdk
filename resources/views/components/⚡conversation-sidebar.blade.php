<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public array $conversations = [];
    public ?string $activeConversationId = null;
    public string $search = '';

    public function mount(): void
    {
        $this->loadConversations();
    }

    public function updatedSearch(): void
    {
        $this->loadConversations();
    }

    // Loading Conversations
    #[On('conversation-saved')]
    public function loadConversations(?string $conversationId = null): void
    {
        if ($conversationId) {
            $this->activeConversationId = $conversationId;
        }

        $query = DB::table('agent_conversations');

        if (!empty($this->search)) {
            $query->where('title', 'like', '%' . $this->search . '%');
        }

        $this->conversations = $query
            ->orderBy('is_pinned', 'desc')
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->map(
                fn($conversation) => [
                    'id' => $conversation->id,
                    'title' => Str::limit($conversation->title ?: 'New Conversation', 34),
                    'is_pinned' => (bool) $conversation->is_pinned,
                ],
            )
            ->all();
    }

    public function newConversation(): void
    {
        $this->activeConversationId = null;

        $this->dispatch('new-conversation');
    }

    public function togglePin(string $conversationId): void
    {
        $conversation = DB::table('agent_conversations')->where('id', $conversationId)->first();
        if ($conversation) {
            DB::table('agent_conversations')
                ->where('id', $conversationId)
                ->update([
                    'is_pinned' => !$conversation->is_pinned,
                ]);
            $this->loadConversations();
        }
    }

    public function renameConversation(string $conversationId, string $newTitle): void
    {
        if (trim($newTitle) !== '') {
            DB::table('agent_conversations')
                ->where('id', $conversationId)
                ->update([
                    'title' => trim($newTitle),
                ]);
            $this->loadConversations();
        }
    }

    // Delete Conversation
    public function deleteConversation(string $conversationId): void
    {
        $wasActiveConversation = $this->activeConversationId === $conversationId;

        DB::transaction(function () use ($conversationId) {
            DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->delete();

            DB::table('agent_conversations')->where('id', $conversationId)->delete();
        });

        if ($wasActiveConversation) {
            $this->redirectRoute('chat', navigate: true);

            return;
        }

        // Refresh the conversations
        $this->loadConversations();
    }
};
?>

<style>
    [x-cloak] {
        display: none !important;
    }
</style>

<aside class="w-64 h-full flex-shrink-0 flex flex-col bg-zinc-900 border-r border-zinc-800" x-data="{ searchOpen: false }">

    {{-- Logo --}}
    <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div
                class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center text-white text-sm font-bold shadow-lg">
                L
            </div>

            <div>
                <p class="text-sm font-semibold text-white">LaraChat </p>
                <p class="text-xs text-zinc-500">Powered by Gemini</p>
            </div>
        </div>

        <div class="flex items-center gap-1">
            <button x-on:click="searchOpen = !searchOpen; if(searchOpen) { setTimeout(() => document.getElementById('searchInput').focus(), 50) }" title="Search"
                class="text-zinc-500 hover:text-white transition-colors cursor-pointer rounded-md hover:bg-zinc-800 p-1">
                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </button>
            <button x-on:click="$dispatch('toggle-sidebar')" title="Close sidebar"
                class="text-zinc-500 hover:text-white transition-colors cursor-pointer rounded-md hover:bg-zinc-800 p-1">
                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>
                    <line x1="9" x2="9" y1="3" y2="21"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- New chat button & Search input --}}
    <div class="p-4 space-y-3">
        <button wire:click="newConversation"
            class="w-full flex items-center gap-2 px-4 py-2.5 rounded-lg border border-zinc-700 text-zinc-400 hover:bg-zinc-800 hover:text-white transition-all duration-200 text-sm cursor-pointer">+
            New Conversation</button>

        <div x-show="searchOpen" x-collapse x-cloak class="relative transition-all">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                <svg class="w-4 h-4 text-zinc-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                </svg>
            </div>
            <input id="searchInput" type="text" wire:model.live.debounce.300ms="search" class="block w-full p-2.5 pl-9 text-sm text-zinc-200 border border-zinc-700 rounded-lg bg-zinc-800 focus:ring-violet-500 focus:border-violet-500 placeholder-zinc-500 outline-none" placeholder="Search chats...">
        </div>
    </div>

    {{-- Render Conversations --}}
    <div class="flex-1 overflow-y-auto px-2 pb-4 space-y-1" x-data="{ openMenu: null }">

        @forelse ($conversations as $conversation)
            <div
                class="relative group rounded-lg transition-colors {{ $activeConversationId === $conversation['id'] ? 'bg-zinc-800 text-white' : 'text-zinc-400 hover:bg-zinc-800/70 hover:text-white' }}">

                <a href="{{ route('chat', ['conversationId' => $conversation['id']]) }}"
                    class="block truncate cursor-pointer w-full text-left px-2 pr-16 py-2 rounded-lg text-sm ">
                    {{ $conversation['title'] }}</a>

                <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center">
                    {{-- Pin Icon Button --}}
                    @if ($conversation['is_pinned'])
                        <button wire:click.stop="togglePin('{{ $conversation['id'] }}')" title="Unpin"
                            class="cursor-pointer rounded-md p-1 text-violet-400 hover:bg-zinc-700 hover:text-violet-300 transition mr-0.5">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path d="M12 17v5" />
                                <path
                                    d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z" />
                            </svg>
                        </button>
                    @else
                        <button wire:click.stop="togglePin('{{ $conversation['id'] }}')" title="Pin"
                            class="cursor-pointer rounded-md p-1 text-zinc-500 opacity-0 transition hover:bg-zinc-700 hover:text-white group-hover:opacity-100 mr-0.5">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 17v5" />
                                <path
                                    d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z" />
                            </svg>
                        </button>
                    @endif

                    {{-- Three dots button --}}
                    <button
                        x-on:click.stop="openMenu = openMenu === @js($conversation['id']) ? null : @js($conversation['id'])"
                        class="cursor-pointer rounded-md p-1 text-zinc-500 opacity-0 transition hover:bg-zinc-700 hover:text-white group-hover:opacity-100">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="5" cy="12" r="1.8" />
                            <circle cx="12" cy="12" r="1.8" />
                            <circle cx="19" cy="12" r="1.8" />
                        </svg>
                    </button>
                </div>

                {{-- Dropdown menu --}}
                <div x-cloak x-show="openMenu === @js($conversation['id'])" x-on:click.outside="openMenu = null"
                    class="absolute right-2 top-9 z-50 w-36 rounded-lg border border-zinc-700 bg-zinc-900 p-1 shadow-xl">

                    {{-- Rename --}}
                    <button
                        x-on:click="openMenu = null; $dispatch('open-rename-modal', { id: @js($conversation['id']), title: @js($conversation['title']) })"
                        class="cursor-pointer flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-800">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                        </svg>
                        Rename
                    </button>

                    {{-- Pin/Unpin --}}
                    <button wire:click.stop="togglePin('{{ $conversation['id'] }}')" x-on:click="openMenu = null"
                        class="cursor-pointer flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-800">
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 17v5" />
                            <path
                                d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z" />
                        </svg>
                        {{ $conversation['is_pinned'] ? 'Unpin' : 'Pin' }}
                    </button>

                    <div class="h-px bg-zinc-700 my-1 mx-2"></div>

                    {{-- Delete --}}
                    <button x-on:click="openMenu = null; $dispatch('open-delete-modal', { id: @js($conversation['id']) })"
                        class="cursor-pointer flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-red-400 hover:bg-red-500/10">

                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M3 6h18" />
                            <path d="M8 6V4h8v2" />
                            <path d="M6 6l1 14h10l1-14" />
                        </svg>
                        Delete</button>
                </div>

            </div>
        @empty

            <p class="px-3 py-2 text-xs text-zinc-600">No conversations yet</p>
        @endforelse

    </div>



    {{-- Status footer --}}
    <div class="p-4 border-t border-zinc-800 space-y-2">
        <div class="flex items-center gap-2 px-2 py-2 rounded-lg bg-zinc-800/60">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            <span class="text-xs text-zinc-400">Gemini 2.5 Flash </span>
        </div>

        <p class="text-xs text-zinc-600 px-2">Built with Laravel 13 AI SDK </p>
    </div>

    {{-- Rename Modal --}}
    <div x-data="{
        renameModalOpen: false,
        renameId: null,
        renameTitle: '',
        openModal(e) {
            this.renameId = e.detail.id;
            this.renameTitle = e.detail.title;
            this.renameModalOpen = true;
            setTimeout(() => { $refs.renameInput.select() }, 50);
        },
        saveRename() {
            if (this.renameTitle.trim() !== '') {
                $wire.renameConversation(this.renameId, this.renameTitle);
            }
            this.renameModalOpen = false;
        }
    }" x-on:open-rename-modal.window="openModal($event)" x-show="renameModalOpen"
        style="display: none;"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
        x-transition.opacity>

        <div class="bg-[#282828] border border-zinc-700/50 rounded-2xl shadow-2xl w-full max-w-[400px] overflow-hidden relative"
            x-on:click.outside="renameModalOpen = false" x-show="renameModalOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">

            <button x-on:click="renameModalOpen = false"
                class="absolute top-4 right-4 text-zinc-400 hover:text-white transition-colors cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>

            <div class="p-6">
                <h3 class="text-[1.35rem] font-bold text-white mb-1">Rename chat</h3>
                <p class="text-[0.95rem] text-zinc-400 mb-5">Keep it short and recognizable</p>

                <input type="text" x-ref="renameInput" x-model="renameTitle" x-on:keydown.enter="saveRename"
                    class="w-full bg-[#282828] border border-blue-600 rounded-xl px-4 py-3 text-zinc-200 focus:outline-none focus:ring-1 focus:ring-blue-600 transition-all selection:bg-blue-600 selection:text-white">

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" x-on:click="renameModalOpen = false"
                        class="px-5 py-2.5 rounded-full border border-zinc-600 text-sm font-medium text-zinc-300 hover:bg-zinc-700 transition-colors cursor-pointer">
                        Cancel
                    </button>
                    <button type="button" x-on:click="saveRename"
                        class="px-5 py-2.5 rounded-full bg-zinc-200 text-sm font-semibold text-black hover:bg-white transition-colors cursor-pointer">
                        Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Delete Modal --}}
    <div x-data="{
        deleteModalOpen: false,
        deleteId: null,
        openModal(e) {
            this.deleteId = e.detail.id;
            this.deleteModalOpen = true;
        },
        confirmDelete() {
            if (this.deleteId) {
                $wire.deleteConversation(this.deleteId);
            }
            this.deleteModalOpen = false;
        }
    }" x-on:open-delete-modal.window="openModal($event)" x-show="deleteModalOpen"
        style="display: none;"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
        x-transition.opacity>

        <div class="bg-[#282828] border border-zinc-700/50 rounded-2xl shadow-2xl w-full max-w-[400px] overflow-hidden relative"
            x-on:click.outside="deleteModalOpen = false" x-show="deleteModalOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">

            <button x-on:click="deleteModalOpen = false"
                class="absolute top-4 right-4 text-zinc-400 hover:text-white transition-colors cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>

            <div class="p-6 text-center mt-2">
                <div class="w-12 h-12 rounded-full bg-red-500/20 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6 text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 6h18" />
                        <path d="M8 6V4h8v2" />
                        <path d="M6 6l1 14h10l1-14" />
                    </svg>
                </div>
                <h3 class="text-[1.35rem] font-bold text-white mb-2">Delete Chat?</h3>
                <p class="text-[0.95rem] text-zinc-400 mb-6">Are you sure you want to delete this conversation? This action cannot be undone.</p>

                <div class="flex justify-center gap-3">
                    <button type="button" x-on:click="deleteModalOpen = false"
                        class="px-5 py-2.5 rounded-full border border-zinc-600 text-sm font-medium text-zinc-300 hover:bg-zinc-700 transition-colors cursor-pointer">
                        Cancel
                    </button>
                    <button type="button" x-on:click="confirmDelete"
                        class="px-5 py-2.5 rounded-full bg-red-500 text-sm font-semibold text-white hover:bg-red-600 transition-colors cursor-pointer">
                        Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
</aside>
