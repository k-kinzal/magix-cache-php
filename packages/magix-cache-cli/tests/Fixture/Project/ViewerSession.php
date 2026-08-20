<?php

declare(strict_types=1);

namespace Tests\Package\Cli\Fixture\Project;

/**
 * Supplies the viewer of the current request.
 */
final class ViewerSession
{
    /**
     * Returns the identifier of the signed in viewer.
     */
    public function viewerId(): int
    {
        return 1;
    }
}
