// SPDX-License-Identifier: MIT
pragma solidity ^0.8.24;

import "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import "@openzeppelin/contracts/token/ERC20/extensions/ERC20Pausable.sol";
import "@openzeppelin/contracts/access/Ownable.sol";

/**
 * @title IDTC - Invest Energy-Development Coin
 * @notice ERC-20 token with burn, pause, and owner controls.
 * @dev Total supply of 100,000,000 IDTC minted to deployer on construction.
 */
contract IDTC is ERC20, ERC20Burnable, ERC20Pausable, Ownable {
    uint256 public constant MAX_SUPPLY = 100_000_000 * 10 ** 18;

    constructor(address initialOwner)
        ERC20("Invest Energy-Development Coin", "IDTC")
        Ownable(initialOwner)
    {
        _mint(initialOwner, MAX_SUPPLY);
    }

    /**
     * @notice Pause all token transfers. Only owner.
     */
    function pause() external onlyOwner {
        _pause();
    }

    /**
     * @notice Unpause token transfers. Only owner.
     */
    function unpause() external onlyOwner {
        _unpause();
    }

    // Required override: ERC20 + ERC20Pausable both define _update
    function _update(address from, address to, uint256 value)
        internal
        override(ERC20, ERC20Pausable)
    {
        super._update(from, to, value);
    }
}
