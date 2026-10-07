<?php

use Livewire\Component;

new class extends Component {
    public ?string $conversationId = null;
    public array $messages = [];

    public function mount(?string $conversationId = null): void
    {
        $this->conversationId = $conversationId;

        if ($conversationId) {
            $this->loadMessages($conversationId);
        }
    }

    // Load Messages
    public function loadMessages($conversationId): void
    {
        $this->messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get()
            ->map(function ($message) {
                $attachment = null;

                if ($message->role === 'user') {
                    $decoded = json_decode($message->attachments, true) ?: [];
                    if (isset($decoded[0])) {
                        $fileData = $decoded[0];
                        $attachment = [
                            'path' => 'chat-attachments/' . ($fileData['name'] ?? ''),
                            'name' => $fileData['name'] ?? null,
                            'mime' => str_contains($fileData['type'] ?? '', 'image') ? 'image/png' : 'application/pdf',
                        ];
                    }
                }

                return [
                    'role' => $message->role,
                    'content' => $message->content,
                    'streaming' => false,
                    'attachment_path' => $attachment['path'] ?? null,
                    'attachment_name' => $attachment['name'] ?? null,
                    'attachment_mime' => $attachment['mime'] ?? null,
                ];
            })
            ->all();
    }
};
?>

<div class="flex h-screen overflow-hidden bg-zinc-950" x-data="{ sidebarOpen: true }" @toggle-sidebar.window="sidebarOpen = !sidebarOpen">

    {{-- Sidebar Component --}}
    <div x-show="sidebarOpen" 
         x-transition:enter="transition-all ease-in-out duration-300"
         x-transition:enter-start="-ml-64 opacity-0"
         x-transition:enter-end="ml-0 opacity-100"
         x-transition:leave="transition-all ease-in-out duration-300"
         x-transition:leave-start="ml-0 opacity-100"
         x-transition:leave-end="-ml-64 opacity-0"
         class="h-full flex-shrink-0 relative">
        <livewire:conversation-sidebar :active-conversation-id="$conversationId" />
    </div>

    {{-- Chatbox component --}}
    <div class="flex-1 flex flex-col relative min-w-0 min-h-0">
        <livewire:chat-box :key="$conversationId ?? 'main-chat-box'" :initial-messages="$messages" :conversation-id="$conversationId" />
    </div>

</div>
