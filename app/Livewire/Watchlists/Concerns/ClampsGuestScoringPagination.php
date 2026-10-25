<?php

namespace App\Livewire\Watchlists\Concerns;

use Livewire\WithPagination;

trait ClampsGuestScoringPagination
{
    use WithPagination {
        getPage as baseGetPage;
        setPage as baseSetPage;
        resetPage as baseResetPage;
        previousPage as basePreviousPage;
        nextPage as baseNextPage;
        gotoPage as baseGotoPage;
    }

    /**
     * Determine whether the current user is restricted to guest preview scoring.
     */
    public function isGuestScoringUser(): bool
    {
        $user = auth()->user();

        return ! $user || $user->isGuest();
    }

    /**
     * Get the clamped pagination page for guest scoring previews.
     */
    public function guestClampedPage(): int
    {
        return 4;
    }

    /**
     * Livewire boot hook for trait.
     */
    public function bootClampsGuestScoringPagination(): void
    {
        $this->enforceGuestScoringPagination();
    }

    /**
     * Livewire mount hook for trait.
     */
    public function mountClampsGuestScoringPagination(): void
    {
        $this->enforceGuestScoringPagination();
    }

    /**
     * Livewire rendering hook for trait.
     */
    public function renderingClampsGuestScoringPagination(): void
    {
        $this->enforceGuestScoringPagination();
    }

    /**
     * Enforce guest page clamp on paginators array.
     */
    protected function enforceGuestScoringPagination(): void
    {
        if ($this->isGuestScoringUser()) {
            $this->paginators['page'] = $this->guestClampedPage();
        }
    }

    /**
     * Get the active pagination page, enforcing guest clamping.
     */
    public function getPage($pageName = 'page')
    {
        if ($pageName === 'page' && $this->isGuestScoringUser()) {
            return $this->guestClampedPage();
        }

        return $this->baseGetPage($pageName);
    }

    /**
     * Set the pagination page, preventing guest navigation off page 4.
     */
    public function setPage($page, $pageName = 'page')
    {
        if ($pageName === 'page' && $this->isGuestScoringUser()) {
            $page = $this->guestClampedPage();
        }

        $this->baseSetPage($page, $pageName);
    }

    /**
     * Goto page, clamping to page 4 for guests.
     */
    public function gotoPage($page, $pageName = 'page')
    {
        if ($pageName === 'page' && $this->isGuestScoringUser()) {
            $this->setPage($this->guestClampedPage(), $pageName);

            return;
        }

        $this->baseGotoPage($page, $pageName);
    }

    /**
     * Reset pagination, ensuring guests remain clamped to page 4.
     */
    public function resetPage($pageName = 'page')
    {
        if ($pageName === 'page' && $this->isGuestScoringUser()) {
            $this->setPage($this->guestClampedPage(), $pageName);

            return;
        }

        $this->baseResetPage($pageName);
    }

    /**
     * Previous page action, clamping to page 4 for guests.
     */
    public function previousPage($pageName = 'page')
    {
        if ($pageName === 'page' && $this->isGuestScoringUser()) {
            $this->setPage($this->guestClampedPage(), $pageName);

            return;
        }

        $this->basePreviousPage($pageName);
    }

    /**
     * Next page action, clamping to page 4 for guests.
     */
    public function nextPage($pageName = 'page')
    {
        if ($pageName === 'page' && $this->isGuestScoringUser()) {
            $this->setPage($this->guestClampedPage(), $pageName);

            return;
        }

        $this->baseNextPage($pageName);
    }

    /**
     * Property updating hook for Livewire 3 query string updates.
     */
    public function updatingPaginators(&$value, $name): void
    {
        if ($name === 'page' && $this->isGuestScoringUser()) {
            $value = $this->guestClampedPage();
        }
    }

    /**
     * Property updated hook for Livewire 3.
     */
    public function updatedPaginators(): void
    {
        $this->enforceGuestScoringPagination();
    }
}
